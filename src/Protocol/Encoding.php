<?php

namespace BiometricPush\AmtPush\Protocol;

/** Value encodings used in M50 / AMT messages. */
class Encoding
{
    /**
     * Text (user / department names): UTF-16 little-endian + "\0\0" terminator, base64.
     */
    public static function utf16(string $text): string
    {
        return base64_encode(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8') . "\x00\x00");
    }

    public static function fromUtf16(?string $base64): string
    {
        $raw = base64_decode((string) $base64, true);
        if ($raw === false || $raw === '') {
            return '';
        }
        // drop terminator pairs from the end only (ASCII letters have a \0 high byte)
        while (strlen($raw) >= 2 && substr($raw, -2) === "\x00\x00") {
            $raw = substr($raw, 0, -2);
        }

        return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }

    /** Card / QR numbers: 32-bit little-endian integer, base64. 0 ("AAAAAA==") = none. */
    public static function int32(int $value): string
    {
        return base64_encode(pack('V', $value & 0xFFFFFFFF));
    }

    public static function fromInt32(?string $base64): int
    {
        $raw = base64_decode((string) $base64, true);
        if ($raw === false || strlen($raw) < 4) {
            return 0;
        }
        $v = unpack('V', substr($raw, 0, 4));

        return isset($v[1]) ? (int) $v[1] : 0;
    }

    /** UserPeriod dates: ((year - 2000) << 16) | (month << 8) | day. */
    public static function packDate(int $year, int $month, int $day): int
    {
        $year = max(2000, min(2255, $year));
        $month = max(1, min(12, $month));
        $day = max(1, min(31, $day));

        return (($year - 2000) << 16) | ($month << 8) | $day;
    }

    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function unpackDate(int $value): array
    {
        return [(($value >> 16) & 0xFF) + 2000, ($value >> 8) & 0xFF, $value & 0xFF];
    }

    /** Templates / photos inside logged XML → "[12345 chars]". */
    public static function maskPayload(string $xml): string
    {
        $masked = preg_replace_callback(
            '#<(FingerData|FaceData|PalmData|PhotoData|LogImage)>([^<]{16,})</\1>#',
            function ($m) {
                return '<' . $m[1] . '>[' . strlen($m[2]) . ' chars]</' . $m[1] . '>';
            },
            $xml
        );

        return $masked !== null ? $masked : $xml;
    }
}
