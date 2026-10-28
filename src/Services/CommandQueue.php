<?php

namespace BiometricPush\AmtPush\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use BiometricPush\AmtPush\Exceptions\ReadOnlyDeviceException;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;

/**
 * Queues requests for devices. The server sends them one at a time per device; a batch runs
 * in sequence order and (optionally) stops at the first failure.
 */
class CommandQueue
{
    /** Big payloads need longer (face templates ~37 KB). */
    public const TIMEOUTS = [
        'SetFaceData' => 60, 'GetFaceData' => 60, 'SetFingerData' => 30, 'GetFingerData' => 30,
        'SetPalmData' => 60, 'GetPalmData' => 60, 'SetUserPhoto' => 30, 'GetUserPhoto' => 30,
        'EmptyAllData' => 60, 'EmptyUserEnrollmentData' => 60, 'EmptyTimeLog' => 60,
    ];

    /**
     * @param array $attrs batch_id, sequence, abort_batch_on_failure, employee_id, user_id, source, created_by,
     *                     timeout_seconds, max_attempts, available_at
     * @throws ReadOnlyDeviceException for a write on a read-only device
     */
    public function queue(Device $device, string $request, array $payload = [], array $attrs = []): Command
    {
        if ($device->isReadOnly() && !in_array($request, Command::READ_REQUESTS, true)) {
            throw ReadOnlyDeviceException::forDevice($device->serial_number);
        }

        return Command::create(array_merge([
            'device_id'       => $device->id,
            'request'         => $request,
            'payload'         => $payload,
            'status'          => Command::STATUS_PENDING,
            'attempts'        => 0,
            'max_attempts'    => (int) config('amt-push.commands.max_attempts', 3),
            'timeout_seconds' => isset(self::TIMEOUTS[$request]) ? self::TIMEOUTS[$request]
                : (int) config('amt-push.commands.default_timeout_seconds', 15),
            'user_id'         => isset($payload['user_id']) ? (string) $payload['user_id'] : null,
            'source'          => 'manual',
        ], $attrs));
    }

    /**
     * Several requests that run in this order on the device.
     *
     * @param array $steps each [request, payload, attrs?]
     * @return Collection|Command[]
     */
    public function batch(Device $device, array $steps, bool $abortOnFailure = true, array $attrs = []): Collection
    {
        if ($device->isReadOnly()) {
            foreach ($steps as $step) {
                if (!in_array($step[0], Command::READ_REQUESTS, true)) {
                    throw ReadOnlyDeviceException::forDevice($device->serial_number);
                }
            }
        }
        $batchId = isset($attrs['batch_id']) ? $attrs['batch_id'] : (string) Str::uuid();
        $out = new Collection();
        $sequence = 0;
        foreach ($steps as $step) {
            $stepAttrs = isset($step[2]) ? (array) $step[2] : [];
            $out->push($this->queue($device, $step[0], isset($step[1]) ? (array) $step[1] : [], array_merge($attrs, [
                'batch_id'               => $batchId,
                'sequence'               => isset($stepAttrs['sequence']) ? $stepAttrs['sequence'] : $sequence,
                'abort_batch_on_failure' => $abortOnFailure,
            ], $stepAttrs)));
            $sequence++;
        }

        return $out;
    }

    /** A request with the same payload is already waiting: don't queue it twice. */
    public function waiting(Device $device, string $request, array $payload): ?Command
    {
        return Command::where('device_id', $device->id)->where('request', $request)
            ->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_SENT])->orderBy('id')->get()
            ->first(function (Command $c) use ($payload) {
                return (array) $c->payload == $payload;
            });
    }

    public function cancel(Command $cmd, string $reason = 'Cancelled'): bool
    {
        if ($cmd->status !== Command::STATUS_PENDING) {
            return false;
        }
        $cmd->forceFill(['status' => Command::STATUS_CANCELLED, 'error' => $reason, 'finished_at' => now()])->save();
        app(ResultApplier::class)->afterFinished($cmd->device, $cmd, null);

        return true;
    }

    /** Cancel every write still waiting for a device (read_only switched on). */
    public function cancelWrites(Device $device, string $reason): int
    {
        $writes = Command::where('device_id', $device->id)->where('status', Command::STATUS_PENDING)
            ->whereNotIn('request', Command::READ_REQUESTS)->get();
        foreach ($writes as $cmd) {
            $this->cancel($cmd, $reason);
        }

        return $writes->count();
    }

    /** Fail the rest of a batch after a step with abort_batch_on_failure failed for good. */
    public function abortRestOfBatch(Command $failed): int
    {
        if (!$failed->abort_batch_on_failure || !$failed->batch_id) {
            return 0;
        }
        $rest = Command::where('device_id', $failed->device_id)->where('batch_id', $failed->batch_id)
            ->where('sequence', '>', $failed->sequence)->where('status', Command::STATUS_PENDING)->get();
        foreach ($rest as $cmd) {
            $cmd->forceFill([
                'status' => Command::STATUS_FAILED, 'error' => 'aborted: command #' . $failed->id . ' failed', 'finished_at' => now(),
            ])->save();
            app(ResultApplier::class)->afterFinished($cmd->device, $cmd, null);
        }

        return $rest->count();
    }
}
