<?php

namespace BiometricPush\AmtPush\Models;

/** A request queued for a device and the device's answer. One is in flight per device at a time. */
class Command extends AmtModel
{
    protected $baseTable = 'commands';

    protected $fillable = [
        'device_id', 'request', 'payload', 'batch_id', 'sequence', 'abort_batch_on_failure', 'status', 'attempts',
        'max_attempts', 'timeout_seconds', 'available_at', 'employee_id', 'user_id', 'response', 'error', 'source',
        'created_by', 'sent_at', 'finished_at',
    ];

    protected $casts = [
        'device_id'              => 'integer',
        'sequence'               => 'integer',
        'attempts'               => 'integer',
        'max_attempts'           => 'integer',
        'timeout_seconds'        => 'integer',
        'abort_batch_on_failure' => 'boolean',
        'payload'                => 'array',
        'available_at'           => 'datetime',
        'sent_at'                => 'datetime',
        'finished_at'            => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_CANCELLED = 'cancelled';

    /** Requests that only read: the only ones allowed on a read-only device. */
    public const READ_REQUESTS = [
        'GetFirstGlog', 'GetNextGlog', 'GetGlogPosInfo', 'GetUserData', 'GetFirstUserData', 'GetNextUserData',
        'GetFingerData', 'GetFaceData', 'GetPalmData', 'GetUserPhoto', 'GetTime', 'GetDeviceInfoAll',
        'GetDeviceStatusAll', 'GetFirmwareVersion', 'GetDepartment',
    ];

    /** Requests that can lose punches the device has not uploaded yet. */
    public const NEED_ALL_LOGS = ['EmptyTimeLog', 'EmptyAllData', 'DeleteGlogWithPos'];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function isFinished(): bool
    {
        return !in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENT], true);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function hasRetriesLeft(): bool
    {
        return (int) $this->attempts < (int) $this->max_attempts;
    }

    public function markSent(): void
    {
        $this->forceFill(['status' => self::STATUS_SENT, 'attempts' => (int) $this->attempts + 1, 'sent_at' => now()])->save();
    }

    public function markSucceeded(?string $response): void
    {
        $this->forceFill(['status' => self::STATUS_SUCCEEDED, 'response' => $response, 'error' => null, 'finished_at' => now()])->save();
    }

    /** Failed: back to pending after the retry delay while attempts are left (unless $final). */
    public function markFailed(string $error, bool $final = false, ?string $response = null): void
    {
        $retry = !$final && $this->hasRetriesLeft();
        $this->forceFill([
            'status'       => $retry ? self::STATUS_PENDING : self::STATUS_FAILED,
            'error'        => mb_substr($error, 0, 255),
            'response'     => $response !== null ? $response : $this->response,
            'available_at' => $retry ? now()->addSeconds((int) config('amt-push.commands.retry_delay_seconds', 30)) : $this->available_at,
            'finished_at'  => $retry ? null : now(),
        ])->save();
    }

    public function markTimedOut(): void
    {
        $retry = $this->hasRetriesLeft();
        $this->forceFill([
            'status'       => $retry ? self::STATUS_PENDING : self::STATUS_TIMED_OUT,
            'error'        => 'no response within ' . (int) $this->timeout_seconds . 's',
            'available_at' => $retry ? now()->addSeconds((int) config('amt-push.commands.retry_delay_seconds', 30)) : $this->available_at,
            'finished_at'  => $retry ? null : now(),
        ])->save();
    }
}
