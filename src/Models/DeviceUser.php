<?php

namespace BiometricPush\AmtPush\Models;

/** One user (UserID) on one device, optionally linked to an employee of your app. */
class DeviceUser extends AmtModel
{
    protected $baseTable = 'device_users';

    protected $fillable = [
        'device_id', 'user_id', 'employee_id', 'match_status', 'name', 'privilege', 'department', 'enabled',
        'fingers_mask', 'palms_mask', 'face_enrolled', 'sync_status', 'last_command_id', 'last_error',
        'synced_at', 'removed_at', 'seen_at', 'last_read_id',
    ];

    protected $casts = [
        'device_id'       => 'integer',
        'department'      => 'integer',
        'enabled'         => 'boolean',
        'fingers_mask'    => 'integer',
        'palms_mask'      => 'integer',
        'face_enrolled'   => 'boolean',
        'last_command_id' => 'integer',
        'synced_at'       => 'datetime',
        'removed_at'      => 'datetime',
        'seen_at'         => 'datetime',
    ];

    public const PRIVILEGES = ['User', 'Manager', 'Administrator'];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function isOnDevice(): bool
    {
        return !in_array($this->sync_status, ['removed', 'removing'], true);
    }

    /** Finger slots enrolled (from <Fingers>: bit 2n = finger n enrolled, bit 2n+1 = duress). */
    public function enrolledFingers(): array
    {
        $out = [];
        for ($n = 0; $n < 10; $n++) {
            if ((int) $this->fingers_mask & (1 << (2 * $n))) {
                $out[] = $n;
            }
        }

        return $out;
    }

    public function enrolledPalms(): array
    {
        $out = [];
        for ($n = 0; $n < 10; $n++) {
            if ((int) $this->palms_mask & (1 << $n)) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
