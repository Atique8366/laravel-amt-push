<?php

namespace BiometricPush\Adms\Models;

/** One user (PIN) on one device, optionally linked to an employee of your app. */
class DeviceUser extends AdmsModel
{
    protected $baseTable = 'device_users';

    protected $fillable = [
        'device_id', 'pin', 'employee_id', 'match_status', 'name', 'privilege', 'password', 'card', 'tz',
        'sync_status', 'sync_hash', 'last_command_id', 'last_error', 'synced_at', 'removed_at', 'seen_at', 'last_read_id',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'device_id'       => 'integer',
        'last_command_id' => 'integer',
        'synced_at'       => 'datetime',
        'removed_at' => 'datetime',
        'seen_at'    => 'datetime',
    ];

    /** Device roles (ZKTeco privilege values) as offered in the device menu. */
    public const ROLES = [
        'user'        => 0,
        'enroller'    => 2,
        'admin'       => 6,
        'super_admin' => 14,
    ];

    public static function roleName($privilege): ?string
    {
        if ($privilege === null || $privilege === '') {
            return null;
        }
        $name = array_search((int) $privilege, self::ROLES, true);

        return $name !== false ? $name : 'level_' . (int) $privilege;
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function lastCommand()
    {
        return $this->belongsTo(Command::class, 'last_command_id');
    }

    public function isOnDevice(): bool
    {
        return !in_array($this->sync_status, ['removed', 'removing'], true);
    }
}
