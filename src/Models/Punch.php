<?php

namespace BiometricPush\Adms\Models;

/** One attendance record (ATTLOG line) uploaded by a device. */
class Punch extends AdmsModel
{
    protected $baseTable = 'punches';

    protected $fillable = [
        'device_id', 'serial_number', 'pin', 'punched_at', 'punch_status', 'verify_mode', 'work_code',
        'mask_flag', 'temperature', 'raw_line', 'employee_id', 'processed_at', 'process_result', 'process_attempts',
    ];

    protected $casts = [
        'device_id'        => 'integer',
        'process_attempts' => 'integer',
        'punched_at'       => 'datetime',
        'processed_at' => 'datetime',
    ];

    /** ZKTeco verify modes (the most common ones). */
    public const VERIFY_MODES = [
        0  => 'password_or_finger_or_card',
        1  => 'finger',
        2  => 'pin',
        3  => 'password',
        4  => 'card',
        5  => 'finger_or_password',
        6  => 'finger_or_card',
        7  => 'card_or_password',
        8  => 'pin_and_finger',
        9  => 'finger_and_password',
        10 => 'card_and_finger',
        11 => 'card_and_password',
        12 => 'finger_and_password_and_card',
        13 => 'pin_and_finger_and_password',
        15 => 'face',
        16 => 'face_and_finger',
        17 => 'face_and_password',
        18 => 'face_and_card',
        25 => 'palm',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function verifyModeName(): ?string
    {
        if ($this->verify_mode === null || $this->verify_mode === '') {
            return null;
        }

        return isset(self::VERIFY_MODES[(int) $this->verify_mode]) ? self::VERIFY_MODES[(int) $this->verify_mode] : 'other';
    }

    public function scopePending($query)
    {
        return $query->whereNull('processed_at');
    }
}
