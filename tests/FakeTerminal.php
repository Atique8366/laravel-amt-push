<?php

namespace BiometricPush\AmtPush\Tests;

use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Protocol\Frame;
use BiometricPush\AmtPush\Server\Kernel;

/**
 * An AMT800 terminal speaking the M50 WebSocket protocol (frames as documented and as observed
 * from AMT800 firmware: un-padded "-T" times, Result tags, TransID acknowledgements).
 */
class FakeTerminal
{
    /** @var Kernel */
    private $kernel;

    /** @var FakeSocket */
    public $socket;

    /** @var string */
    public $sn;

    /** @var string|null */
    public $token;

    /** @var int frames already read through next() */
    private $read = 0;

    public function __construct(Kernel $kernel, string $sn)
    {
        $this->kernel = $kernel;
        $this->sn = $sn;
        $this->connect();
    }

    public function connect(): self
    {
        $this->socket = new FakeSocket();
        $this->read = 0;
        $this->kernel->open($this->socket);

        return $this;
    }

    public function disconnect(): void
    {
        $this->kernel->close($this->socket);
    }

    /** Send raw XML body tags inside <Message> with the usual header. */
    public function send(array $tags, bool $header = true): void
    {
        $xml = '<?xml version="1.0"?><Message>';
        if ($header) {
            $xml .= '<TerminalType>AMT800</TerminalType><TerminalID>1</TerminalID><DeviceSerialNo>' . $this->sn . '</DeviceSerialNo>';
        }
        foreach ($tags as $tag => $value) {
            $xml .= '<' . $tag . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $tag . '>';
        }
        $this->kernel->message($this->socket, $xml . '</Message>');
    }

    public function register(string $cloudId = 'cloud-1'): Frame
    {
        $this->send(['Request' => 'Register', 'CloudId' => $cloudId, 'FirmwareVersion' => 'AMT800 v3.3.0', 'ProductName' => 'AMT800']);
        $reply = $this->find('Register');
        if ($reply && $reply->get('Token') !== '') {
            $this->token = $reply->get('Token');
        }

        return $reply;
    }

    public function login(?string $token = null, string $cloudId = 'cloud-1'): Frame
    {
        $this->send(['Request' => 'Login', 'Token' => $token !== null ? $token : (string) $this->token, 'CloudId' => $cloudId]);

        return $this->find('Login');
    }

    public function punch(int $logId, string $userId, string $time = '2026-10-28-T09:00:05Z', string $action = 'FP',
                          string $attend = 'Duty On', string $transId = 'T1'): ?Frame
    {
        $this->send(['Event' => 'TimeLog_v2', 'LogID' => $logId, 'UtcTimezoneMinutes' => 300, 'Time' => $time, 'UserID' => $userId,
            'Action' => $action, 'AttendStat' => $attend, 'APStat' => 'None', 'JobCode' => 0, 'Photo' => 'No', 'TransID' => $transId]);

        return $this->find('TimeLog_v2');
    }

    public function adminLog(int $logId, string $action, string $userId, string $adminId = '1'): ?Frame
    {
        $this->send(['Event' => 'AdminLog_v2', 'LogID' => $logId, 'Time' => '2026-10-28-T09:10:00Z', 'AdminID' => $adminId,
            'UserID' => $userId, 'Action' => $action, 'Stat' => 0, 'TransID' => 'A' . $logId]);

        return $this->find('AdminLog_v2');
    }

    public function keepAlive(): ?Frame
    {
        $this->send(['Event' => 'KeepAlive', 'DevTime' => '2026-10-28-T09:00:00Z']);

        return $this->find('KeepAlive');
    }

    /** Let the server send queued commands; returns the request the device now has to answer. */
    public function poll(): ?Frame
    {
        $this->kernel->tick();

        return $this->lastRequest();
    }

    /** The last <Request> the server sent and not yet answered by us. */
    public function lastRequest(): ?Frame
    {
        $last = null;
        for ($i = $this->read; $i < count($this->socket->sent); $i++) {
            $frame = Frame::parse($this->socket->sent[$i]);
            if ($frame && $frame->type() === 'request') {
                $last = $frame;
                $this->read = $i + 1;
            }
        }

        return $last;
    }

    /** Answer the request in flight: <Response>name</Response> + tags. */
    public function answer(string $request, array $tags = ['Result' => 'OK']): ?Frame
    {
        $this->send(['Response' => $request] + $tags);

        return $this->lastRequest();   // the next request may follow right away
    }

    /** The latest frame the server sent with this Response name. */
    public function find(string $response): ?Frame
    {
        for ($i = count($this->socket->sent) - 1; $i >= 0; $i--) {
            $frame = Frame::parse($this->socket->sent[$i]);
            if ($frame && $frame->get('Response') === $response) {
                return $frame;
            }
        }

        return null;
    }

    /** Requests sent so far (names). */
    public function requests(): array
    {
        $out = [];
        foreach ($this->socket->sent as $xml) {
            $f = Frame::parse($xml);
            if ($f && $f->type() === 'request') {
                $out[] = $f->get('Request');
            }
        }

        return $out;
    }

    /** Register (+approve before) and drain the automatic SetTime. */
    public function online(): self
    {
        $this->register();
        $req = $this->lastRequest();
        while ($req) {
            $req = $this->answer($req->get('Request'));
        }

        return $this;
    }

    /** Answer every request the server sends with Result=OK until nothing is left. */
    public function answerAllOk(int $max = 100): array
    {
        $done = [];
        $req = $this->lastRequest() ?: $this->poll();
        while ($req && $max-- > 0) {
            $done[] = $req->get('Request');
            $req = $this->answer($req->get('Request')) ?: $this->poll();
        }

        return $done;
    }

    public static function name(string $text): string
    {
        return Encoding::utf16($text);
    }
}
