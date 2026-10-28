<?php

namespace BiometricPush\AmtPush\Protocol;

use Illuminate\Support\Carbon;

/**
 * Device timestamps on the wire: "2026-4-23-T11:00:03Z" or "2026-04-23-T11:00:03Z".
 * There is a "-" before the "T" and month / day may be unpadded. The "Z" is decorative:
 * the value is the device's LOCAL clock, not UTC.
 */
class DeviceTime
{
    /** Device timestamp → Carbon in $timezone (default app timezone), or null when unreadable. */
    public static function parse(?string $value, ?string $timezone = null): ?Carbon
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})[-\s]?T?\s*(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?Z?$/i', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59) {
            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5],
            isset($m[6]) ? (int) $m[6] : 0, $timezone ?: (config('app.timezone') ?: 'UTC'));
    }

    /** "2026-04-23-T11:00:03Z" (KeepAlive ServerTime, GetFirstGlog filters). */
    public static function format(\DateTimeInterface $time): string
    {
        return $time->format('Y-m-d') . '-T' . $time->format('H:i:s') . 'Z';
    }

    /** "2026-4-23-T11:00:03Z": unpadded month / day, as SetTime expects. */
    public static function formatUnpadded(\DateTimeInterface $time): string
    {
        return $time->format('Y-n-j') . '-T' . $time->format('H:i:s') . 'Z';
    }
}
