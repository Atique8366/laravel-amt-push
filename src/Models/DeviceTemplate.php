<?php

namespace BiometricPush\Adms\Models;

/** Which version of a stored template is on which device. */
class DeviceTemplate extends AdmsModel
{
    protected $baseTable = 'device_templates';

    protected $fillable = [
        'device_id', 'template_id', 'pin', 'template_hash', 'status', 'last_command_id', 'last_error', 'synced_at',
    ];

    protected $casts = [
        'device_id'       => 'integer',
        'template_id'     => 'integer',
        'last_command_id' => 'integer',
        'synced_at'       => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(BiometricTemplate::class, 'template_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
