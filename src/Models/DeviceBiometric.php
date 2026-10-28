<?php

namespace BiometricPush\AmtPush\Models;

/** Which version of a stored template is on which device. */
class DeviceBiometric extends AmtModel
{
    protected $baseTable = 'device_biometrics';

    protected $fillable = [
        'device_id', 'biometric_id', 'user_id', 'template_hash', 'status', 'last_command_id', 'last_error', 'synced_at',
    ];

    protected $casts = [
        'device_id'       => 'integer',
        'biometric_id'    => 'integer',
        'last_command_id' => 'integer',
        'synced_at'       => 'datetime',
    ];

    public function biometric()
    {
        return $this->belongsTo(Biometric::class, 'biometric_id');
    }
}
