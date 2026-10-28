<?php

namespace BiometricPush\AmtPush;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Services\BiometricService;
use BiometricPush\AmtPush\Services\CommandQueue;
use BiometricPush\AmtPush\Services\LogService;
use BiometricPush\AmtPush\Services\PunchProcessor;
use BiometricPush\AmtPush\Services\UserService;

/**
 * One device, zkteco-library style:  Amt::device('AMT800-0001')->syncTime();
 *
 * Devices stay connected to the amt:serve process. Every method that talks to the device QUEUES a
 * request and returns it; the server sends it within a second when the device is online (or when
 * it reconnects) and the answer lands in $command->fresh()->status (and fires CommandFinished).
 * Methods that read (users(), attendance(), templates) return what is stored in the database.
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

    /** Who queues the next requests (saved in commands.created_by). */
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

    /** Blocked devices are refused at Register / Login; a connected one is refused at its next login. */
    public function block(): self
    {
        $this->device->forceFill(['registration_status' => Device::STATUS_BLOCKED, 'token' => null])->save();

        return $this;
    }

    /** Read-only guard: only reading requests are sent; waiting writes are cancelled. */
    public function readOnly(bool $on = true): self
    {
        $this->device->forceFill(['read_only' => $on])->save();
        if ($on) {
            app(CommandQueue::class)->cancelWrites($this->device, 'Cancelled: device set to read-only');
        }

        return $this;
    }

    /** name, tenant_id, timezone, process_punches, punches_from, capture_biometrics, meta, ... */
    public function update(array $attributes): self
    {
        $this->device->fill($attributes)->save();

        return $this;
    }

    public function isOnline(): bool
    {
        return (bool) $this->device->fresh()->is_online;
    }

    // ── Device ───────────────────────────────────────────────────────────

    /** Set the device clock to the server's time (in the device's timezone). */
    public function syncTime(): Command
    {
        return $this->queue('SetTime');
    }

    /** Disable (punches refused, still online) or enable the terminal. */
    public function enable(bool $on = true): Command
    {
        return $this->queue('EnableDevice', ['enable' => $on]);
    }

    /** Restart the terminal (LockControl mode 5). */
    public function restart(): Command
    {
        return $this->queue('LockControl', ['mode' => 5]);
    }

    /** Door / lock: 1 force open, 2 force close, 3 normal open, 4 auto recover, 5 reboot, 6 cancel alarm. */
    public function lockControl(int $mode): Command
    {
        return $this->queue('LockControl', ['mode' => $mode]);
    }

    /** Ask for firmware, settings, counts and log position (stored on the device row). */
    public function refreshInfo(): Collection
    {
        return app(CommandQueue::class)->batch($this->device, [
            ['GetFirmwareVersion', []], ['GetDeviceInfoAll', []], ['GetDeviceStatusAll', []], ['GetGlogPosInfo', []],
        ], false, ['created_by' => $this->by]);
    }

    public function setDepartment(int $number, string $name): Command
    {
        return $this->queue('SetDepartment', ['dept_no' => $number, 'name' => $name]);
    }

    /** Any request of the M50 WebSocket protocol: command('SetDeviceInfo', ['ParamName' => 'SoundVolume', 'Value' => 5]). */
    public function command(string $request, array $tags = []): Command
    {
        return $this->queue($request, ['tags' => $tags]);
    }

    // ── Users ────────────────────────────────────────────────────────────

    /**
     * Add or update a user. Only what you pass is written; everything else stays as on the device.
     *
     * @param array $options privilege (User|Manager|Administrator), department, enabled, password, card, qr,
     *                       timesets [5 x 0-49], period [start, end], allow_no_certificate, employee_id
     */
    public function setUser(string $userId, ?string $name = null, array $options = []): Command
    {
        $user = ['user_id' => $userId] + $options;
        if ($name !== null) {
            $user['name'] = $name;
        }

        return app(UserService::class)->setUser($this->device, $user, 'manual', $this->by);
    }

    /** Delete a user (with their templates) from the device. */
    public function removeUser(string $userId): Command
    {
        return app(UserService::class)->deleteUser($this->device, $userId, 'manual', $this->by);
    }

    /** Read one user from the device (name, privilege, enrolled fingers / face / palms). */
    public function readUser(string $userId): Command
    {
        return $this->queue('GetUserData', ['user_id' => $userId]);
    }

    /** Read every user on the device; users no longer there are marked removed. */
    public function refreshUsers(): Command
    {
        return app(UserService::class)->readAll($this->device, 'manual', $this->by);
    }

    /** Users known for this device (stored). */
    public function users(bool $includeRemoved = false): Collection
    {
        return $this->device->users()->when(!$includeRemoved, function ($q) {
            $q->where('sync_status', '!=', 'removed');
        })->get()->sortBy(function (DeviceUser $u) {
            return (int) $u->user_id;
        })->values();
    }

    public function user(string $userId): ?DeviceUser
    {
        return $this->device->users()->where('user_id', $userId)->first();
    }

    /** Link a UserID to one of your employees (punches and templates follow; unmatched punches are reprocessed). */
    public function linkUser(string $userId, $employeeId, bool $reprocessPunches = true): DeviceUser
    {
        $row = app(UserService::class)->link($this->device, $userId, (string) $employeeId);
        if ($reprocessPunches) {
            $ids = Punch::where('device_id', $this->device->id)->where('user_id', $userId)
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

    public function unlinkUser(string $userId): ?DeviceUser
    {
        return app(UserService::class)->unlink($this->device, $userId);
    }

    // ── Attendance ───────────────────────────────────────────────────────

    /** Stored punches, optionally between two times (device local time) and for one UserID. */
    public function attendance($from = null, $to = null, ?string $userId = null): Collection
    {
        return Punch::where('device_id', $this->device->id)
            ->when($from !== null, function ($q) use ($from) {
                $q->where('punched_at', '>=', $from);
            })
            ->when($to !== null, function ($q) use ($to) {
                $q->where('punched_at', '<=', $to);
            })
            ->when($userId !== null, function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderBy('punched_at')->orderBy('id')->get();
    }

    /**
     * Fetch punches stored on the device after the last known LogID ($full: all of them).
     *
     * @return string|null batch id, or null when a pull is already running
     */
    public function pullAttendance(bool $full = false): ?string
    {
        return app(LogService::class)->pull($this->device, $full, 'manual', $this->by);
    }

    /** One user's punches from the device, optionally between two times. */
    public function pullAttendanceFor(string $userId, $from = null, $to = null): string
    {
        return app(LogService::class)->pullFor($this->device, $userId, $from, $to, $this->by);
    }

    /** Delete all punches ON THE DEVICE: pulled first, sent only once every punch is stored. */
    public function clearAttendance(): Command
    {
        return app(LogService::class)->clear($this->device, 'EmptyTimeLog', $this->by);
    }

    /** Delete users, templates and logs on the device (settings stay). Logs are pulled first. */
    public function clearAllData(): Command
    {
        return app(LogService::class)->clear($this->device, 'EmptyAllData', $this->by);
    }

    /** Delete every user and template on the device (logs stay). */
    public function clearUsers(): Command
    {
        return $this->queue('EmptyUserEnrollmentData');
    }

    /** Delete the device's management (admin) log. */
    public function clearAdminLog(): Command
    {
        return $this->queue('EmptyManageLog');
    }

    public function processPunches(?int $limit = null): array
    {
        return app(PunchProcessor::class)->processPending($this->device, $limit);
    }

    /** @param array|null $punchIds null = all punches whose result is one of $results */
    public function reprocessPunches(?array $punchIds = null, array $results = ['unmatched']): int
    {
        return app(PunchProcessor::class)->reset($this->device, $punchIds, $results);
    }

    // ── Biometrics ───────────────────────────────────────────────────────

    /** Read a UserID's templates from the device (finger | face | palm | photo). */
    public function pullTemplates(string $userId, array $kinds = ['finger', 'face']): int
    {
        return app(BiometricService::class)->pull($this->device, $userId, $kinds, 'manual', $this->by);
    }

    /** Send an employee's stored templates to this device (their user must be on it). */
    public function pushTemplates($employeeId, bool $force = false): Collection
    {
        $userId = DeviceUser::where('device_id', $this->device->id)->where('employee_id', (string) $employeeId)
            ->where('sync_status', '!=', 'removed')->value('user_id');
        if ($userId === null) {
            throw new \InvalidArgumentException("Employee {$employeeId} is not on device {$this->device->serial_number}: sync the user first.");
        }
        $steps = app(BiometricService::class)->pushSteps($this->device, (string) $employeeId, (string) $userId, $force, 'manual', $this->by);

        return $steps ? app(CommandQueue::class)->batch($this->device, $steps, true, ['created_by' => $this->by]) : new Collection();
    }

    // ── Commands ─────────────────────────────────────────────────────────

    public function commands(?string $status = null): Collection
    {
        return Command::where('device_id', $this->device->id)->when($status !== null, function ($q) use ($status) {
            $q->where('status', $status);
        })->orderByDesc('id')->get();
    }

    public function cancel(int $commandId): bool
    {
        $cmd = Command::where('device_id', $this->device->id)->whereKey($commandId)->first();

        return $cmd ? app(CommandQueue::class)->cancel($cmd) : false;
    }

    protected function queue(string $request, array $payload = []): Command
    {
        return app(CommandQueue::class)->queue($this->device, $request, $payload, ['created_by' => $this->by]);
    }
}
