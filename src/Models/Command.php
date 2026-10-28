<?php

namespace BiometricPush\Adms\Models;

/** A command queued for a device, and the device's answer. */
class Command extends AdmsModel
{
    protected $baseTable = 'commands';

    protected $fillable = [
        'device_id', 'type', 'command', 'device_user_id', 'employee_id', 'meta', 'status', 'return_code',
        'response', 'attempts', 'queued_at', 'sent_at', 'answered_at', 'source', 'created_by',
    ];

    protected $casts = [
        'device_id'      => 'integer',
        'device_user_id' => 'integer',
        'return_code'    => 'integer',
        'attempts'       => 'integer',
        'meta'           => 'array',
        'queued_at'   => 'datetime',
        'sent_at'     => 'datetime',
        'answered_at' => 'datetime',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** Commands that only read from the device: the only ones allowed on a read-only device. */
    public const READ_TYPES = ['info', 'query_users', 'bio_query', 'recollect'];

    /** Safe to send again when the device didn't answer (REBOOT / CLEAR are never re-sent). */
    public const RETRIABLE = ['user_update', 'user_delete', 'info', 'query_users', 'recollect', 'bio_update', 'bio_query'];

    /** Meaning of the device's Return= code (ZKTeco PUSH protocol). */
    public const RETURN_CODES = [
        0     => 'Success',
        -1    => 'Parameter error',
        -2    => 'Photo data size mismatch',
        -3    => 'Read/write error',
        -9    => 'Template data size mismatch',
        -10   => 'User not found on device',
        -11   => 'Invalid template format',
        -12   => 'Invalid template',
        -1001 => 'Device capacity reached',
        -1002 => 'Not supported by this device',
        -1003 => 'Command timed out on device',
        -1004 => 'Data does not match device settings',
        -1005 => 'Device busy',
        -1006 => 'Data too long',
        -1007 => 'Device memory error',
        -1008 => 'Device could not get data from server',
    ];

    public static function returnMeaning(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }
        if ($code > 0) {
            return 'Success (' . $code . ' records)';
        }

        return isset(self::RETURN_CODES[$code]) ? self::RETURN_CODES[$code] : 'Error ' . $code;
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function deviceUser()
    {
        return $this->belongsTo(DeviceUser::class, 'device_user_id');
    }

    public function isFinished(): bool
    {
        return !in_array($this->status, [self::STATUS_QUEUED, self::STATUS_SENT], true);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** Command text safe to show: passwords and templates hidden. */
    public function safeCommand(): string
    {
        $text = preg_replace('/Passwd=[^\t]*/', 'Passwd=***', (string) $this->command);

        return \BiometricPush\Adms\Support\Protocol::maskBiometrics($text);
    }
}
