<?php

namespace BiometricPush\Adms\Support;

use Illuminate\Http\Request;
use BiometricPush\Adms\Models\Device;

/**
 * Text helpers for the ZKTeco ADMS / PUSH (iclock) protocol.
 *
 * Wire format (PushProtVer 2.4.1): the query string carries SN / table / Stamp,
 * bodies are "\n"-separated lines with "\t"-separated fields, replies are text/plain.
 */
class Protocol
{
    public const PUSH_PROTOCOL_VERSION = '2.4.1';
    public const SERVER_VERSION = '2.4.1';

    /** Max bytes of a request body kept in the request log / device options. */
    public const LOG_BODY_CAP = 65535;

    /** Parameters the device is asked to report in its table=options upload. */
    public const PUSH_OPTIONS = 'FingerFunOn,FaceFunOn,FPVersion,FaceVersion,FvFunOn,FvVersion,PvFunOn,PvVersion,'
        . 'MultiBioDataSupport,MultiBioPhotoSupport,MultiBioVersion,MultiBioCount,MaxMultiBioDataCount,MaxMultiBioPhotoCount';

    /** Case-insensitive query parameter (devices send SN, some firmware sn). */
    public static function param(Request $request, string $name): ?string
    {
        foreach ($request->query() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? null : trim((string) $value);
            }
        }

        return null;
    }

    /** Body → non-empty lines, "\r" stripped. */
    public static function lines(string $body): array
    {
        $out = [];
        foreach (preg_split("/\n/", $body) as $line) {
            $line = rtrim($line, "\r\0");
            if (trim($line) !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * One ATTLOG line: PIN \t time \t status \t verify \t workcode \t reserved1 \t reserved2 [\t mask \t temperature]
     * Returns null when the line has no PIN or no valid time.
     */
    public static function parseAttlogLine(string $line): ?array
    {
        $f = explode("\t", $line);
        $pin = trim(isset($f[0]) ? $f[0] : '');
        $time = trim(isset($f[1]) ? $f[1] : '');
        if ($pin === '' || $time === '' || strlen($pin) > 32) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $time)
            ?: \DateTime::createFromFormat('Y-m-d H:i', $time);
        if (!$dt) {
            return null;
        }

        $opt = function ($i, $max) use ($f) {
            $v = isset($f[$i]) ? trim($f[$i]) : '';
            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        return [
            'pin'          => $pin,
            'punched_at'   => $dt->format('Y-m-d H:i:s'),
            'punch_status' => $opt(2, 8),
            'verify_mode'  => $opt(3, 8),
            'work_code'    => $opt(4, 32),
            'mask_flag'    => $opt(7, 8),
            'temperature'  => $opt(8, 16),
            'raw_line'     => mb_substr($line, 0, 500),
        ];
    }

    /**
     * "key=value" pairs separated by comma, tab or newline (table=options body).
     * Keys keep their case; a leading "~" is dropped.
     */
    public static function keyValues(string $text): array
    {
        $out = [];
        foreach (preg_split('/[,\t\n\r]+/', $text) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $key = ltrim(trim(substr($pair, 0, $pos)), '~');
            if ($key !== '' && !array_key_exists($key, $out)) {
                $out[$key] = trim(substr($pair, $pos + 1));
            }
        }

        return $out;
    }

    /** Case-insensitive lookup in a keyValues() array. */
    public static function kv(array $kv, string ...$names): ?string
    {
        foreach ($names as $name) {
            foreach ($kv as $key => $value) {
                if (strcasecmp($key, $name) === 0 && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** "PIN=1\tName=A, B\tPri=0" → array (tab-separated only: names may contain commas). */
    public static function tabFields(string $text): array
    {
        $out = [];
        foreach (explode("\t", $text) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos !== false && $pos > 0) {
                $key = trim(substr($pair, 0, $pos));
                if (!array_key_exists($key, $out)) {
                    $out[$key] = substr($pair, $pos + 1);
                }
            }
        }

        return $out;
    }

    /** "ID=12&Return=0&CMD=DATA" → ['ID'=>'12','Return'=>'0','CMD'=>'DATA'] (values may contain '='). */
    public static function resultFields(string $line): array
    {
        $out = [];
        foreach (explode('&', trim($line)) as $part) {
            $pos = strpos($part, '=');
            if ($pos !== false) {
                $out[substr($part, 0, $pos)] = substr($part, $pos + 1);
            }
        }

        return $out;
    }

    /**
     * getrequest INFO parameter:
     * firmware, users, fingerprints, attendance records, ip, fp algorithm, face algorithm, face enroll count, faces, flags
     */
    public static function parseInfo(string $info): array
    {
        $p = array_map('trim', explode(',', $info));
        $int = function ($i) use ($p) {
            return isset($p[$i]) && ctype_digit($p[$i]) ? (int) $p[$i] : null;
        };
        $str = function ($i, $max) use ($p) {
            return isset($p[$i]) && $p[$i] !== '' ? self::cleanText(str_replace('%20', ' ', $p[$i]), $max) : null;
        };

        return array_filter([
            'firmware_version' => $str(0, 100),
            'user_count'       => $int(1),
            'fp_count'         => $int(2),
            'attlog_count'     => $int(3),
            'device_ip'        => isset($p[4]) && filter_var($p[4], FILTER_VALIDATE_IP) ? $p[4] : null,
            'fp_algorithm'     => $str(5, 16),
            'face_algorithm'   => $str(6, 16),
            'face_count'       => $int(8),
        ], function ($v) {
            return $v !== null;
        });
    }

    /** Handshake reply for GET /iclock/cdata?options=all. */
    public static function handshakeOptions(Device $device): string
    {
        $attlog = $device->attlog_stamp ?: '0';
        $operlog = $device->operlog_stamp ?: '0';
        $attphoto = $device->attphoto_stamp ?: '0';

        $lines = [
            'GET OPTION FROM: ' . $device->serial_number,
            'ATTLOGStamp=' . $attlog,
            'OPERLOGStamp=' . $operlog,
            'ATTPHOTOStamp=' . $attphoto,
            // legacy names, still read by older firmware
            'Stamp=' . $attlog,
            'OpStamp=' . $operlog,
            'PhotoStamp=' . $attphoto,
            'ErrorDelay=' . (int) $device->error_delay_seconds,
            'Delay=' . (int) $device->delay_seconds,
            'TransTimes=' . ($device->trans_times !== null ? $device->trans_times : ''),
            'TransInterval=' . (int) $device->trans_interval,
            'TransFlag=' . ($device->trans_flag ?: config('adms.devices.trans_flag')),
            'TimeZone=' . $device->timezoneMinutes(),
            'Realtime=' . ($device->realtime ? '1' : '0'),
            'Encrypt=0',
            'ServerVer=' . self::SERVER_VERSION . ' ' . date('Y-m-d'),
            'PushProtVer=' . self::PUSH_PROTOCOL_VERSION,
            'PushOptionsFlag=1',
            'PushOptions=' . self::PUSH_OPTIONS,
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * Body made safe for a utf8mb4 text column: binary → placeholder, biometric templates /
     * photos masked, invalid UTF-8 replaced, capped at LOG_BODY_CAP bytes.
     */
    public static function bodyForLog(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        if (strpos($body, "\0") !== false) {
            return '[binary ' . strlen($body) . ' bytes]';
        }
        $body = self::maskBiometrics($body);
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }
        if (strlen($body) > self::LOG_BODY_CAP) {
            $body = mb_strcut($body, 0, self::LOG_BODY_CAP, 'UTF-8') . "\n[cut, " . strlen($body) . ' bytes total]';
        }

        return $body;
    }

    /**
     * Device text (user names, model / firmware strings) made safe for a utf8mb4 column.
     * Valid UTF-8 is only trimmed / cut. Single-byte Latin text from older firmware
     * (Windows-1252) is converted. NUL padding and control characters are removed.
     */
    public static function cleanText(?string $text, int $max): string
    {
        $text = (string) $text;
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_substr(trim((string) $text), 0, $max);
    }

    /** "TMP=<base64>" / "Tmp=" / "Content=" (templates, photos) → "TMP=[1460 chars]". */
    public static function maskBiometrics(string $text): string
    {
        $masked = preg_replace_callback('/\b(TMP|Tmp|tmp|Content|content)=([A-Za-z0-9+\/=]{16,})/', function ($m) {
            return $m[1] . '=[' . strlen($m[2]) . ' chars]';
        }, $text);

        return $masked !== null ? $masked : $text;
    }

    /** Tabs / line breaks removed: a field value must not break the command line. */
    public static function field($value): string
    {
        return (string) preg_replace('/[\t\r\n]+/', ' ', (string) $value);
    }
}
