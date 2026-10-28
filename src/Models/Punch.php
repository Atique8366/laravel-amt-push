<?php

namespace BiometricPush\AmtPush\Models;

/** One attendance record (TimeLog_v2 event or pulled GLog). */
class Punch extends AmtModel
{
    protected $baseTable = 'punches';

    protected $fillable = [
        'device_id', 'serial_number', 'log_epoch', 'log_id', 'user_id', 'punched_at', 'utc_offset_minutes', 'action',
        'attend_stat', 'ap_stat', 'job_code', 'photo', 'log_image', 'latitude', 'longitude', 'source', 'employee_id',
        'processed_at', 'process_result', 'process_attempts',
    ];

    protected $hidden = ['log_image'];

    protected $casts = [
        'device_id'          => 'integer',
        'log_epoch'          => 'integer',
        'log_id'             => 'integer',
        'utc_offset_minutes' => 'integer',
        'job_code'           => 'integer',
        'photo'              => 'boolean',
        'process_attempts'   => 'integer',
        'punched_at'         => 'datetime',
        'processed_at'       => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /** Verification method from <Action>: "FP" → ['finger'], "FACE+CD" → ['face', 'card']. */
    public function methods(): array
    {
        $map = ['FP' => 'finger', 'FACE' => 'face', 'PWD' => 'password', 'CD' => 'card', 'QR' => 'qr', 'PALM' => 'palm'];
        $out = [];
        foreach (explode('+', strtoupper((string) $this->action)) as $part) {
            if (isset($map[trim($part)])) {
                $out[] = $map[trim($part)];
            }
        }

        return $out;
    }

    public function scopePending($query)
    {
        return $query->whereNull('processed_at');
    }
}
