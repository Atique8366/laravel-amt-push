<?php

namespace BiometricPush\AmtPush\Services;

use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;

/**
 * Pulling stored logs from a device (GetFirstGlog → GetNextGlog → … until Result=Fail) and
 * clearing them safely.
 */
class LogService
{
    /** Sequence of the clear step: after any pull chain of the same batch. */
    public const CLEAR_SEQUENCE = 10000000;

    /** @var CommandQueue */
    private $commands;

    public function __construct(CommandQueue $commands)
    {
        $this->commands = $commands;
    }

    /**
     * Fetch punches after the last known LogID (or all of them with $full / when none is known).
     *
     * @return string|null batch id (null: a pull is already running)
     */
    public function pull(Device $device, bool $full = false, string $source = 'manual', ?string $createdBy = null): ?string
    {
        if ($this->running($device)) {
            return null;
        }
        $begin = (!$full && $device->last_log_id !== null) ? (int) $device->last_log_id + 1 : 0;
        $batch = $this->commands->batch($device, [
            ['GetGlogPosInfo', []],
            ['GetFirstGlog', ['begin_log_pos' => $begin, 'cursor' => true]],
        ], false, ['source' => $source, 'created_by' => $createdBy]);

        return $batch->first()->batch_id;
    }

    /** Catch-up pull after a (re)connect, only when a LogID is already known (never a full history on its own). */
    public function pullIfKnown(Device $device): ?string
    {
        return $device->last_log_id !== null ? $this->pull($device, false, 'auto') : null;
    }

    /** One user's punches, optionally between two times (does not move the log position). */
    public function pullFor(Device $device, string $userId, $from = null, $to = null, ?string $createdBy = null): string
    {
        $payload = array_filter([
            'begin_log_pos' => 0, 'user_id' => $userId,
            'start_time' => $from instanceof \DateTimeInterface ? $from->format('Y-m-d H:i:s') : $from,
            'end_time'   => $to instanceof \DateTimeInterface ? $to->format('Y-m-d H:i:s') : $to,
        ], function ($v) {
            return $v !== null;
        });

        return $this->commands->batch($device, [['GetFirstGlog', $payload]], false, ['created_by' => $createdBy])->first()->batch_id;
    }

    /**
     * Clear the device's attendance log (EmptyTimeLog) or everything (EmptyAllData). The log is pulled
     * first, and with commands.wait_for_uploads the clear is only sent once every punch the
     * device reports is stored.
     */
    public function clear(Device $device, string $request = 'EmptyTimeLog', ?string $createdBy = null): Command
    {
        $begin = $device->last_log_id !== null ? (int) $device->last_log_id + 1 : 0;
        $batch = $this->commands->batch($device, [
            ['GetGlogPosInfo', []],
            ['GetFirstGlog', ['begin_log_pos' => $begin, 'cursor' => true]],
            [$request, [], ['sequence' => self::CLEAR_SEQUENCE]],
        ], false, ['created_by' => $createdBy]);

        return $batch->last();
    }

    public function running(Device $device): bool
    {
        return Command::where('device_id', $device->id)->whereIn('request', ['GetFirstGlog', 'GetNextGlog'])
            ->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_SENT])->get()
            ->contains(function (Command $c) {
                return !empty($c->payload['cursor']);
            });
    }
}
