<?php

namespace BiometricPush\Adms\Tests;

/**
 * Speaks the ZKTeco PUSH wire format the way an MB20 (firmware 8.0.4.3, push 2.0.33S) does:
 * query string carries SN / table / Stamp, body is \n-separated lines with \t-separated fields.
 */
class DeviceSimulator
{
    /** @var TestCase */
    private $test;

    /** @var string */
    public $sn;

    /** @var array commands received on the last poll: [id => text] */
    public $received = [];

    public function __construct(TestCase $test, string $sn)
    {
        $this->test = $test;
        $this->sn = $sn;
    }

    /** GET /iclock/cdata?SN=..&options=all&pushver=..&language=.. */
    public function handshake()
    {
        return $this->test->call('GET', '/iclock/cdata?SN=' . $this->sn . '&options=all&pushver=2.4.1&language=69');
    }

    /** POST /iclock/cdata?SN=..&table=..&Stamp=.. */
    public function upload(string $table, $lines, ?string $stamp = '9999')
    {
        $body = is_array($lines) ? implode("\n", $lines) . "\n" : $lines;
        $query = 'SN=' . $this->sn . '&table=' . $table . ($stamp !== null ? '&Stamp=' . $stamp : '');

        return $this->test->call('POST', '/iclock/cdata?' . $query, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    public function punch(string $pin, string $time, string $verify = '1', string $status = '255')
    {
        return $this->upload('ATTLOG', ["{$pin}\t{$time}\t{$status}\t{$verify}\t0\t0\t0\t255\t0"]);
    }

    /** GET /iclock/getrequest?SN=..[&INFO=..]; remembers the commands received. */
    public function poll(?string $info = null)
    {
        $response = $this->test->call('GET', '/iclock/getrequest?SN=' . $this->sn . ($info !== null ? '&INFO=' . rawurlencode($info) : ''));
        $this->received = [];
        foreach (preg_split("/\n/", (string) $response->getContent()) as $line) {
            if (preg_match('/^C:(\d+):(.*)$/', $line, $m)) {
                $this->received[(int) $m[1]] = $m[2];
            }
        }

        return $response;
    }

    /** POST /iclock/devicecmd: one result per command id, optional data lines after it. */
    public function answer(array $results)
    {
        $lines = [];
        foreach ($results as $id => $result) {
            $return = is_array($result) ? $result[0] : $result;
            $cmd = isset($this->received[$id]) ? strtok($this->received[$id], ' ') : 'DATA';
            $lines[] = "ID={$id}&Return={$return}&CMD={$cmd}";
            if (is_array($result)) {
                foreach (array_slice($result, 1) as $data) {
                    $lines[] = $data;
                }
            }
        }

        return $this->test->call('POST', '/iclock/devicecmd?SN=' . $this->sn, [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            implode("\n", $lines) . "\n");
    }

    /** Answer every command received on the last poll with Return=0. */
    public function answerAll(int $return = 0)
    {
        return $this->answer(array_fill_keys(array_keys($this->received), $return));
    }

    /** Typical MB20 capability upload. */
    public function uploadOptions(array $extra = [])
    {
        $kv = array_merge([
            '~DeviceName' => 'MB20', 'FWVersion' => 'Ver 8.0.4.3', 'PushVersion' => '2.0.33S', 'UserCount' => '0',
            'FPCount' => '0', 'TransactionCount' => '0', 'FPVersion' => '10', 'FaceVersion' => '7',
            'IPAddress' => '192.168.1.201', 'PhotoFunOn' => '1', 'MultiBioDataSupport' => '0:1:1:0:0:0:0:0:0:0',
            'MultiBioVersion' => '0:10.0:7.0:0:0:0:0:0:0:0',
        ], $extra);
        $pairs = [];
        foreach ($kv as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return $this->upload('options', implode(',', $pairs), null);
    }

    public static function userLine(string $pin, string $name, int $pri = 0, string $passwd = '', string $card = '0'): string
    {
        return "USER PIN={$pin}\tName={$name}\tPri={$pri}\tPasswd={$passwd}\tCard={$card}\tGrp=1\tTZ=0000000100000000\tVerify=0\tViceCard=";
    }

    public static function fpLine(string $pin, int $fid, string $tmp): string
    {
        return "FP PIN={$pin}\tFID={$fid}\tSize=" . strlen($tmp) . "\tValid=1\tTMP={$tmp}";
    }

    public static function faceLine(string $pin, int $fid, string $tmp): string
    {
        return "FACE PIN={$pin}\tFID={$fid}\tSIZE=" . strlen($tmp) . "\tVALID=1\tTMP={$tmp}";
    }

    public static function template(string $seed, int $length = 120): string
    {
        return substr(str_repeat(base64_encode(sha1($seed, true)), 20), 0, $length);
    }
}
