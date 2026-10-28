<?php

namespace BiometricPush\AmtPush\Models;

/** One biometric template or user photo, stored once per person for all devices. */
class Biometric extends AmtModel
{
    protected $baseTable = 'biometrics';

    protected $fillable = [
        'owner_key', 'employee_id', 'type', 'slot', 'duress', 'template', 'template_hash', 'source_device_id',
        'source_user_id', 'captured_at',
    ];

    protected $hidden = ['template'];

    protected $casts = [
        'slot'             => 'integer',
        'duress'           => 'boolean',
        'source_device_id' => 'integer',
        'captured_at'      => 'datetime',
    ];

    public const FINGER = 'finger';
    public const FACE = 'face';
    public const PALM = 'palm';
    public const PHOTO = 'photo';

    public static function employeeKey(string $employeeId): string
    {
        return 'e:' . $employeeId;
    }

    public static function deviceUserKey(int $deviceId, string $userId): string
    {
        return 'd:' . $deviceId . ':' . $userId;
    }

    public function sourceDevice()
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }
}
