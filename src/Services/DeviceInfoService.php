<?php

namespace BiometricPush\Adms\Services;

use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Support\Protocol;

/**
 * Applies device-reported parameters (table=options upload, or the data lines
 * of an INFO command reply) to the device row.
 */
class DeviceInfoService
{
    /** @return int number of key=value pairs read */
    public function applyOptions(Device $device, string $text, bool $storeRaw = true): int
    {
        $kv = Protocol::keyValues($text);
        $int = function ($v) {
            return $v !== null && ctype_digit($v) ? (int) $v : null;
        };
        $flag = function (...$names) use ($kv) {
            $v = Protocol::kv($kv, ...$names);
            return $v === null ? null : ($v === '1');
        };

        $fp = Protocol::kv($kv, 'FPVersion');
        $face = Protocol::kv($kv, 'FaceVersion');
        $multi = Protocol::kv($kv, 'MultiBioVersion');   // e.g. 0:10.0:7.0:0:0:0:0:0:0:0
        if ($multi !== null) {
            $slots = explode(':', $multi);
            if ($fp === null && isset($slots[1]) && $slots[1] !== '0') {
                $fp = $slots[1];
            }
            if ($face === null && isset($slots[2]) && $slots[2] !== '0') {
                $face = $slots[2];
            }
        }

        $ip = Protocol::kv($kv, 'IPAddress');
        $model = Protocol::kv($kv, 'DeviceName');
        $firmware = Protocol::kv($kv, 'FWVersion', 'FirmVer');
        $push = Protocol::kv($kv, 'PushVersion');

        $device->fill(array_filter([
            'firmware_version'      => $firmware ? Protocol::cleanText($firmware, 100) : null,
            'push_version'          => $push ? Protocol::cleanText($push, 20) : null,
            'user_count'            => $int(Protocol::kv($kv, 'UserCount')),
            'fp_count'              => $int(Protocol::kv($kv, 'FPCount')),
            'face_count'            => $int(Protocol::kv($kv, 'FaceCount')),
            'attlog_count'          => $int(Protocol::kv($kv, 'TransactionCount')),
            'fp_algorithm'          => $fp ? mb_substr($fp, 0, 16) : null,
            'face_algorithm'        => $face ? mb_substr($face, 0, 16) : null,
            'device_ip'             => $ip && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'model'                 => !$device->model && $model ? Protocol::cleanText($model, 255) : null,
            'mask_detection'        => $flag('MaskDetectionFunOn'),
            'temperature_detection' => $flag('IRTempDetectionFunOn'),
        ], function ($v) {
            return $v !== null;
        }));
        if ($storeRaw) {
            $device->options = Protocol::bodyForLog($text);
        }
        $device->save();

        return count($kv);
    }
}
