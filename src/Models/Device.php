<?php

namespace BiometricPush\Adms\Models;

use Carbon\Carbon;

/**
 * A ZKTeco device talking the ADMS / PUSH (iclock) protocol, identified by its serial number.
 */
class Device extends AdmsModel
{
    protected $baseTable = 'devices';

    protected $fillable = [
        'serial_number', 'name', 'tenant_id', 'registration_status', 'push_enabled', 'read_only',
        'process_punches', 'punches_from', 'capture_biometrics',
        'attlog_stamp', 'operlog_stamp', 'attphoto_stamp', 'errorlog_stamp',
        'trans_flag', 'delay_seconds', 'error_delay_seconds', 'realtime', 'trans_interval', 'trans_times',
        'timezone_offset', 'name_max_length',
        'model', 'firmware_version', 'push_version', 'language', 'device_ip', 'user_count', 'fp_count',
        'face_count', 'attlog_count', 'fp_algorithm', 'face_algorithm', 'mask_detection', 'temperature_detection',
        'options', 'meta', 'last_ip', 'last_seen_at', 'last_attlog_at', 'last_log_prune_at',
    ];

    protected $hidden = ['options'];

    protected $casts = [
        'push_enabled'          => 'boolean',
        'read_only'             => 'boolean',
        'process_punches'       => 'boolean',
        'capture_biometrics'    => 'boolean',
        'realtime'              => 'boolean',
        'mask_detection'        => 'boolean',
        'temperature_detection' => 'boolean',
        'delay_seconds'         => 'integer',
        'error_delay_seconds'   => 'integer',
        'trans_interval'        => 'integer',
        'name_max_length'       => 'integer',
        'user_count'            => 'integer',
        'fp_count'              => 'integer',
        'face_count'            => 'integer',
        'attlog_count'          => 'integer',
        'meta'                  => 'array',
        'punches_from'          => 'date',
        'last_seen_at'          => 'datetime',
        'last_attlog_at'        => 'datetime',
        'last_log_prune_at'     => 'datetime',
    ];

    protected $appends = ['is_online'];

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

    public function requestLogs()
    {
        return $this->hasMany(RequestLog::class, 'device_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('registration_status', self::STATUS_APPROVED);
    }

    /** Served by /iclock: approved and enabled. */
    public function canPush(): bool
    {
        return $this->registration_status === self::STATUS_APPROVED && (bool) $this->push_enabled;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->read_only;
    }

    public function isOnline(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }
        $window = config('adms.devices.online_window_seconds') ?: max(60, 3 * (int) $this->delay_seconds);

        return $this->last_seen_at->gt(Carbon::now()->subSeconds((int) $window));
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->isOnline();
    }

    /** "+05:00" / "-03:30" / "5" → minutes east of UTC (the handshake TimeZone value). */
    public function timezoneMinutes(): int
    {
        $tz = trim((string) ($this->timezone_offset ?: config('adms.devices.timezone_offset')));
        if ($tz === '') {
            return (int) round(Carbon::now(config('app.timezone') ?: 'UTC')->getOffset() / 60);
        }
        if (!preg_match('/^([+-])?(\d{1,2})(?::?(\d{2}))?$/', $tz, $m)) {
            return 0;
        }
        $minutes = ((int) $m[2]) * 60 + (int) (isset($m[3]) ? $m[3] : 0);

        return (isset($m[1]) && $m[1] === '-') ? -$minutes : $minutes;
    }
}
