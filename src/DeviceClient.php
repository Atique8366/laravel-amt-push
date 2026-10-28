<?php

namespace BiometricPush\Adms;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Services\BiometricService;
use BiometricPush\Adms\Services\CommandService;
use BiometricPush\Adms\Services\PunchProcessor;
use BiometricPush\Adms\Services\UserService;

/**
 * One device, zkteco-library style:  Adms::device('SN123')->restart();
 *
 * PUSH devices are not called, they poll. Every method that changes the device QUEUES a
 * command and returns it; the device runs it on its next poll (within delay_seconds) and the
 * answer lands in $command->fresh()->status / return_code (and fires CommandFinished).
 * Methods that read (users(), attendance(), templates()) return what is stored in the database.
 */
class DeviceClient
{
    /** @var Device */
    protected $device;

    /** @var string|null */
    protected $by;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    public function model(): Device
    {
        return $this->device;
    }

    /** Who queues the next commands (saved in commands.created_by). */
    public function by($userId): self
    {
        $this->by = $userId !== null ? (string) $userId : null;

        return $this;
    }

    // ── Registration / settings ──────────────────────────────────────────

    public function approve(): self
    {
        $this->device->forceFill(['registration_status' => Device::STATUS_APPROVED])->save();

        return $this;
    }

    public function block(): self
    {
        $this->device->forceFill(['registration_status' => Device::STATUS_BLOCKED])->save();

        return $this;
    }

    /** Read-only guard: only reading commands are accepted; waiting writes are cancelled. */
    public function readOnly(bool $on = true): self
    {
        $this->device->forceFill(['read_only' => $on])->save();
        if ($on) {
            app(CommandService::class)->cancelWrites($this->device, 'Cancelled: device set to read-only');
        }

        return $this;
    }

    /** Update device settings (name, tenant_id, delay_seconds, timezone_offset, process_punches, ...). */
    public function update(array $attributes): self
    {
        $this->device->fill($attributes)->save();

        return $this;
    }

    public function isOnline(): bool
    {
        return $this->device->isOnline();
    }

    // ── Device commands ──────────────────────────────────────────────────

    /** Restart the device. */
    public function restart(): Command
    {
        return $this->queue('reboot', 'REBOOT');
    }

    /** Ask the device to report firmware, counts and capabilities again. */
    public function refreshInfo(): Command
    {
        return $this->queue('info', 'INFO');
    }

    /**
     * Ask the device to upload every punch it holds again (only missing ones are added).
     * Works by resetting the upload position: querying punches by date is refused by many firmwares.
     */
    public function recollectAttendance(): Command
    {
        $this->device->forceFill(['attlog_stamp' => '0'])->save();

        return $this->queue('recollect', 'CHECK');
    }

    /**
     * Delete all punches stored ON THE DEVICE. With commands.wait_for_uploads on, the device only
     * receives it once every punch it reports has been stored here.
     */
    public function clearAttendance(): Command
    {
        return $this->queue('clear_log', 'CLEAR LOG');
    }

    /** Any raw PUSH command, e.g. "SET OPTION Delay=30". Treated as a write. */
    public function command(string $command, string $type = 'custom'): Command
    {
        return $this->queue($type, $command);
    }

    /** Punches on the device that are not stored here yet (null = device never reported its count). */
    public function pendingUploads(): ?int
    {
        return app(CommandService::class)->pendingLogCount($this->device);
    }

    // ── Users ────────────────────────────────────────────────────────────

    /**
     * Add or update a user. Values left out keep what the device has.
     *
     * @param array $options name, privilege (0|2|6|14 or user|enroller|admin|super_admin), password, card,
     *                       group, employee_id (link), force, read_first
     * @return Command|null the command to watch (null: the device already has exactly this user)
     */
    public function setUser(string $pin, ?string $name = null, array $options = []): ?Command
    {
        $user = ['pin' => $pin] + array_diff_key($options, ['read_first' => 1]);
        if ($name !== null) {
            $user['name'] = $name;
        }
        $queued = app(UserService::class)->setUsers($this->device, [$user], $this->options($options));

        return $queued->first();
    }

    /** Add or update several users with one device read. Each: pin, name, privilege, password, card, employee_id. */
    public function setUsers(array $users, array $options = []): Collection
    {
        return app(UserService::class)->setUsers($this->device, $users, $this->options($options));
    }

    /** Delete a user (and their templates) from the device. */
    public function removeUser(string $pin): Command
    {
        return app(UserService::class)->deleteUser($this->device, $pin, 'manual', $this->by);
    }

    /** Ask the device to upload its users (all, or one PIN). */
    public function refreshUsers(?string $pin = null): Command
    {
        return app(UserService::class)->queryUsers($this->device, $pin, 'manual', $this->by);
    }

    /** Users known for this device (stored). */
    public function users(bool $includeRemoved = false): Collection
    {
        return $this->device->users()
            ->when(!$includeRemoved, function ($q) {
                $q->where('sync_status', '!=', 'removed');
            })->orderBy('pin')->get();
    }

    public function user(string $pin): ?DeviceUser
    {
        return $this->device->users()->where('pin', $pin)->first();
    }

    /** Link a device PIN to one of your employees (punches and templates follow the link). */
    public function linkUser(string $pin, $employeeId, bool $reprocessPunches = true): DeviceUser
    {
        $row = app(UserService::class)->link($this->device, $pin, (string) $employeeId);
        if ($reprocessPunches) {
            $ids = Punch::where('device_id', $this->device->id)->where('pin', $pin)
                ->where('process_result', 'unmatched')->pluck('id')->all();
            Punch::whereIn('id', $ids)->update(['employee_id' => (string) $employeeId]);
            if ($ids) {
                $processor = app(PunchProcessor::class);
                $processor->reset($this->device, $ids);
                $processor->dispatch($this->device);
            }
        }

        return $row;
    }

    public function unlinkUser(string $pin): ?DeviceUser
    {
        return app(UserService::class)->unlink($this->device, $pin);
    }

    // ── Attendance ───────────────────────────────────────────────────────

    /**
     * Stored punches, optionally between two dates (device local time).
     *
     * @param \DateTimeInterface|string|null $from
     * @param \DateTimeInterface|string|null $to
     */
    public function attendance($from = null, $to = null, ?string $pin = null): Collection
    {
        return Punch::where('device_id', $this->device->id)
            ->when($from !== null, function ($q) use ($from) {
                $q->where('punched_at', '>=', $from);
            })
            ->when($to !== null, function ($q) use ($to) {
                $q->where('punched_at', '<=', $to);
            })
            ->when($pin !== null, function ($q) use ($pin) {
                $q->where('pin', $pin);
            })
            ->orderBy('punched_at')->orderBy('id')->get();
    }

    /** Hand waiting punches to the configured PunchHandler now. */
    public function processPunches(?int $limit = null): array
    {
        return app(PunchProcessor::class)->processPending($this->device, $limit);
    }

    /**
     * Run punches through the handler again.
     *
     * @param array|null $punchIds null = all punches whose result is one of $results
     */
    public function reprocessPunches(?array $punchIds = null, array $results = ['unmatched']): int
    {
        return app(PunchProcessor::class)->reset($this->device, $punchIds, $results);
    }

    // ── Biometrics ───────────────────────────────────────────────────────

    /** Ask the device to upload the templates of some PINs (or of everyone on it). */
    public function pullTemplates(?array $pins = null): int
    {
        return app(BiometricService::class)->pull($this->device, $pins, $this->by);
    }

    /** Send employees' stored templates to this device (their user must be on it). */
    public function pushTemplates(array $employeeIds, bool $force = false): array
    {
        return app(BiometricService::class)->queueTemplates($this->device, $employeeIds, $force, $this->by);
    }

    // ── Commands ─────────────────────────────────────────────────────────

    public function commands(?string $status = null): Collection
    {
        return Command::where('device_id', $this->device->id)
            ->when($status !== null, function ($q) use ($status) {
                $q->where('status', $status);
            })->orderByDesc('id')->get();
    }

    public function cancel(int $commandId): bool
    {
        $cmd = Command::where('device_id', $this->device->id)->whereKey($commandId)->first();

        return $cmd ? app(CommandService::class)->cancel($cmd, 'Cancelled') : false;
    }

    // ─────────────────────────────────────────────────────────────────────

    protected function queue(string $type, string $command): Command
    {
        return app(CommandService::class)->queue($this->device, $type, $command, ['created_by' => $this->by]);
    }

    protected function options(array $options): array
    {
        $out = ['source' => 'manual', 'created_by' => $this->by];
        if (array_key_exists('read_first', $options)) {
            $out['read_first'] = (bool) $options['read_first'];
        }

        return $out;
    }
}
