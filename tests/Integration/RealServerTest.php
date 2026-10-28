<?php

namespace BiometricPush\AmtPush\Tests\Integration;

use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Protocol\Frame;
use BiometricPush\AmtPush\Tests\Support\WsClient;
use BiometricPush\AmtPush\Tests\TestCase;

/**
 * The real `php artisan amt:serve` (Workerman) in its own process, a real TCP WebSocket client
 * as the terminal, one SQLite file shared by both processes.
 */
class RealServerTest extends TestCase
{
    /** @var string|null one database file per test (Windows keeps closed files locked for a while) */
    private $dbFile;

    /** @var resource|null */
    private $server;

    /** @var int */
    private $port;

    /** @var array */
    private $pipes = [];

    protected function sqliteDatabase(): string
    {
        if (!$this->dbFile) {
            $this->dbFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amt-push-it-' . getmypid() . '-' . uniqid() . '.sqlite';
            touch($this->dbFile);
        }

        return $this->dbFile;
    }

    protected function setUp(): void
    {
        if ($this->usesMysql()) {
            $this->markTestSkipped('The real-server test uses its own SQLite file.');
        }
        parent::setUp();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $this->server = proc_open(
            array_merge([PHP_BINARY], $this->inheritedExtensions(),
                [__DIR__ . '/../Support/serve.php', $autoload, $this->sqliteDatabase(), (string) $this->port]),
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/amt-push-it-out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/amt-push-it-err.log', 'w']],
            $this->pipes
        );
        $this->assertIsResource($this->server, 'server process did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
        if ($this->dbFile) {
            @unlink($this->dbFile);
        }
    }

    /** Extensions this test run has (e.g. enabled with -d) but a plain child PHP would not load. */
    private function inheritedExtensions(): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' -m', $childModules);
        $childModules = array_map('strtolower', array_map('trim', $childModules));
        $args = [];
        foreach (['mbstring', 'pdo_sqlite', 'sqlite3', 'openssl', 'fileinfo', 'sockets'] as $ext) {
            if (extension_loaded($ext) && !in_array($ext, $childModules, true)) {
                $args[] = '-d';
                $args[] = 'extension=' . $ext;
            }
        }
        if ($args && ini_get('extension_dir')) {
            array_unshift($args, '-d', 'extension_dir=' . ini_get('extension_dir'));
        }

        return $args;
    }

    private function device(): WsClient
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if ($client = WsClient::connect('127.0.0.1', $this->port)) {
                return $client;
            }
            usleep(250000);
        }
        $this->fail('server did not accept connections: ' . @file_get_contents(sys_get_temp_dir() . '/amt-push-it-err.log'));
    }

    private function xml(array $tags): string
    {
        $xml = '<?xml version="1.0"?><Message><TerminalType>AMT800</TerminalType><TerminalID>1</TerminalID><DeviceSerialNo>REAL-0001</DeviceSerialNo>';
        foreach ($tags as $tag => $value) {
            $xml .= "<{$tag}>{$value}</{$tag}>";
        }

        return $xml . '</Message>';
    }

    private function waitFor(callable $condition, float $seconds = 8.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(200000);
        }

        return false;
    }

    public function test_device_conversation_over_a_real_websocket()
    {
        Amt::register('REAL-0001');
        $ws = $this->device();

        // Register → Token
        $ws->send($this->xml(['Request' => 'Register', 'CloudId' => 'c1']));
        $reply = Frame::parse((string) $ws->receiveContaining('<Response>Register</Response>'));
        $this->assertSame('OK', $reply->get('Result'));
        $token = $reply->get('Token');
        $this->assertNotSame('', $token);

        // the server sets the clock right after connecting
        $setTime = Frame::parse((string) $ws->receiveContaining('<Request>SetTime</Request>'));
        $this->assertMatchesRegularExpression('/-T\d{2}:\d{2}:\d{2}Z$/', $setTime->get('Time'));
        $ws->send($this->xml(['Response' => 'SetTime', 'Result' => 'OK']));

        // a punch: stored, acknowledged with its TransID, linked (employees.mode = pin)
        $ws->send($this->xml(['Event' => 'TimeLog_v2', 'LogID' => 1, 'Time' => '2026-10-28-T09:00:05Z', 'UserID' => 1001,
            'Action' => 'FACE', 'AttendStat' => 'Duty On', 'APStat' => 'None', 'JobCode' => 0, 'Photo' => 'No', 'TransID' => 'TX1']));
        $ack = Frame::parse((string) $ws->receiveContaining('<Response>TimeLog_v2</Response>'));
        $this->assertSame(['TX1', 'OK'], [$ack->get('TransID'), $ack->get('Result')]);
        $this->assertTrue($this->waitFor(function () {
            return Punch::where('user_id', '1001')->value('employee_id') === '1001';
        }));

        // a command queued from this (web) process reaches the device through the server
        $cmd = Amt::device('REAL-0001')->setUser('1002', 'Jane Doe', ['privilege' => 'User']);
        $request = Frame::parse((string) $ws->receiveContaining('<Request>SetUserData</Request>'));
        $this->assertSame('1002', $request->get('UserID'));
        $this->assertSame('Jane Doe', \BiometricPush\AmtPush\Protocol\Encoding::fromUtf16($request->get('Name')));
        $ws->send($this->xml(['Response' => 'SetUserData', 'UserID' => 1002, 'Type' => 'Set', 'Result' => 'OK']));
        $this->assertTrue($this->waitFor(function () use ($cmd) {
            return $cmd->fresh()->status === 'succeeded';
        }));
        $this->assertSame('synced', DeviceUser::where('user_id', '1002')->value('sync_status'));

        // KeepAlive
        $ws->send($this->xml(['Event' => 'KeepAlive', 'DevTime' => '2026-10-28-T09:01:00Z']));
        $keep = Frame::parse((string) $ws->receiveContaining('<Response>KeepAlive</Response>'));
        $this->assertNotSame('', $keep->get('ServerTime'));

        // the connection closes: offline; a new connection logs in with the token
        $ws->close();
        $this->assertTrue($this->waitFor(function () {
            return !Device::where('serial_number', 'REAL-0001')->value('is_online');
        }));

        $ws = $this->device();
        $ws->send($this->xml(['Request' => 'Login', 'Token' => $token, 'CloudId' => 'c1']));
        $login = Frame::parse((string) $ws->receiveContaining('<Response>Login</Response>'));
        $this->assertSame('OK', $login->get('Result'));
        $this->assertTrue($this->waitFor(function () {
            return (bool) Device::where('serial_number', 'REAL-0001')->value('is_online');
        }));
        $ws->close();
    }

    public function test_unapproved_device_is_refused_over_the_wire()
    {
        $ws = $this->device();
        $ws->send($this->xml(['Request' => 'Register', 'CloudId' => 'c1']));
        $reply = Frame::parse((string) $ws->receiveContaining('<Response>Register</Response>'));

        $this->assertSame('Fail', $reply->get('Result'));
        $this->assertTrue($this->waitFor(function () {
            return Device::where('serial_number', 'REAL-0001')->value('registration_status') === 'pending';
        }));
        $ws->close();
    }
}
