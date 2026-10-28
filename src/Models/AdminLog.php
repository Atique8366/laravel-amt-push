<?php

namespace BiometricPush\AmtPush\Models;

/** An administrative action done on the terminal itself (AdminLog_v2): enrollments, deletions, menu access. */
class AdminLog extends AmtModel
{
    protected $baseTable = 'admin_logs';

    protected $fillable = [
        'device_id', 'log_epoch', 'log_id', 'admin_id', 'user_id', 'action', 'stat', 'occurred_at', 'handled',
    ];

    protected $casts = [
        'device_id'   => 'integer',
        'log_id'      => 'integer',
        'handled'     => 'boolean',
        'occurred_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
