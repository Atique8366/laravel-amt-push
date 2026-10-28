<?php

namespace BiometricPush\Adms\Models;

/** One biometric template (or user photo), stored once per person for all devices. */
class BiometricTemplate extends AdmsModel
{
    protected $baseTable = 'biometric_templates';

    protected $fillable = [
        'owner_key', 'employee_id', 'bio_type', 'bio_no', 'bio_index', 'major_ver', 'minor_ver', 'format',
        'duress', 'size', 'template', 'template_hash', 'source_format', 'source_device_id', 'source_pin', 'captured_at',
    ];

    protected $hidden = ['template'];

    protected $casts = [
        'bio_type'         => 'integer',
        'bio_no'           => 'integer',
        'bio_index'        => 'integer',
        'format'           => 'integer',
        'size'             => 'integer',
        'source_device_id' => 'integer',
        'duress'           => 'boolean',
        'captured_at' => 'datetime',
    ];

    /** ZKTeco BioType values (0 = the user photo shown on the device; not used for matching). */
    public const TYPE_PHOTO = 0;
    public const TYPE_FINGERPRINT = 1;
    public const TYPE_FACE = 2;
    public const TYPE_PALM = 8;
    public const TYPE_VISIBLE_FACE = 9;

    public const TYPE_NAMES = [
        0 => 'photo', 1 => 'fingerprint', 2 => 'face', 3 => 'voice', 4 => 'iris', 5 => 'retina', 6 => 'palmprint',
        7 => 'finger_vein', 8 => 'palm', 9 => 'visible_face',
    ];

    public static function typeName(int $type): string
    {
        return isset(self::TYPE_NAMES[$type]) ? self::TYPE_NAMES[$type] : 'type_' . $type;
    }

    public static function employeeKey(string $employeeId): string
    {
        return 'e:' . $employeeId;
    }

    public static function deviceUserKey(int $deviceId, string $pin): string
    {
        return 'd:' . $deviceId . ':' . $pin;
    }

    public function sourceDevice()
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }
}
