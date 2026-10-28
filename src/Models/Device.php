<?php

namespace BiometricPush\AmtPush\Models;

/** An AMT / M50-protocol terminal, identified by its serial number. */
class Device extends AmtModel
{
    protected $baseTable = 'devices';

    protected $fillable = [
        'serial_number', 'name', 'tenant_id', 'registration_status', 'token', 'read_only', 'process_punches',
        'punches_from', 'capture_biometrics', 'timezone', 'cloud_id', 'terminal_type', 'terminal_id',
        'product_name', 'firmware_version', 'log_count', 'log_capacity', 'user_count', 'info',
        'log_epoch', 'last_log_id', 'cleared_at', 'is_online', 'session_id', 'last_ip', 'last_seen_at',
        'last_punch_at', 'last_pull_at', 'registered_at', 'meta',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'read_only'          => 'boolean',
        'process_punches'    => 'boolean',
        'capture_biometrics' => 'boolean',
        'is_online'          => 'boolean',
        'terminal_id'        => 'integer',
        'log_count'          => 'integer',
        'log_capacity'       => 'integer',
        'user_count'         => 'integer',
        'log_epoch'          => 'integer',
        'last_log_id'        => 'integer',
        'info'               => 'array',
        'meta'               => 'array',
        'punches_from'       => 'date',
        'cleared_at'         => 'datetime',
        'last_seen_at'       => 'datetime',
        'last_punch_at'      => 'datetime',
        'last_pull_at'       => 'datetime',
        'registered_at'      => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_BLOCKED = 'blocked';

    public function punches()
    {
        return $this->hasMany(Punch::class, 'device_id');
    }

    public function users()
    {
        return $this->hasMany(DeviceUser::class, 'device_id');
    }

    public function commands()
    {
        return $this->hasMany(Command::class, 'device_id');
    }

    public function adminLogs()
    {
        return $this->hasMany(AdminLog::class, 'device_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('registration_status', self::STATUS_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->registration_status === self::STATUS_APPROVED;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->read_only;
    }

    /** Timezone of the device clock (SetTime, KeepAlive ServerTime). */
    public function clockTimezone(): string
    {
        return $this->timezone ?: (config('amt-push.devices.timezone') ?: (config('app.timezone') ?: 'UTC'));
    }

    /** Punches stored for the current log epoch (since the last clear). */
    public function storedPunchCount(): int
    {
        return Punch::where('device_id', $this->id)->where('log_epoch', (int) $this->log_epoch)->whereNotNull('log_id')->count();
    }
}
