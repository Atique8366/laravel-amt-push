<?php

namespace BiometricPush\AmtPush;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Contracts\EmployeeResolver;
use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Services\BiometricService;
use BiometricPush\AmtPush\Services\CommandQueue;
use BiometricPush\AmtPush\Services\PunchProcessor;
use BiometricPush\AmtPush\Services\UserService;

/**
 * Entry point of the library (the Amt facade):
 *
 *   Amt::device('AMT800-0001')->setUser('1001', 'Jane Doe');
 *   Amt::syncEmployee(42, ['AMT800-0001', 'AMT800-0002']);
 *   Amt::copyBiometrics(42, ['AMT800-0002']);
 */
class AmtManager
{
    /**
     * @param Device|int|string $device serial number, id or model
     * @throws \InvalidArgumentException when it does not exist
     */
    public function device($device): DeviceClient
    {
        return new DeviceClient($this->findDevice($device));
    }

    /** Register a device before its first connection (approved unless $approved = false). */
    public function register(string $serialNumber, array $attributes = [], bool $approved = true): DeviceClient
    {
        $device = Device::firstOrNew(['serial_number' => $serialNumber]);
        if (!$device->exists) {
            $device->fill(self::newDeviceDefaults($serialNumber));
        }
        $device->fill($attributes);
        if ($approved) {
            $device->registration_status = Device::STATUS_APPROVED;
        }
        $device->save();

        return new DeviceClient($device);
    }

    /** @return \Illuminate\Database\Eloquent\Builder */
    public function devices()
    {
        return Device::query();
    }

    /** @return \Illuminate\Database\Eloquent\Builder */
    public function punches()
    {
        return Punch::query();
    }

    /**
     * Put an employee on devices (user record from the EmployeeResolver), optionally followed by
     * their stored templates, as one batch per device that stops at the first failure.
     *
     * @param array|Device|int|string $devices
     * @param array $options with_biometrics, force_biometrics, user (values overriding the resolver's), created_by
     * @return array per serial number: ['batch_id', 'commands'] or ['skipped' => reason]
     */
    public function syncEmployee($employeeId, $devices, array $options = []): array
    {
        $employeeId = (string) $employeeId;
        $createdBy = isset($options['created_by']) ? (string) $options['created_by'] : null;
        $out = [];

        foreach ($this->findDevices($devices) as $device) {
            $user = app(EmployeeResolver::class)->deviceUser($device, $employeeId);
            if (!$user || empty($user['user_id'])) {
                $out[$device->serial_number] = ['skipped' => 'resolver returned no device user'];
                continue;
            }
            // an employee keeps the UserID they already have on this device
            $existing = DeviceUser::where('device_id', $device->id)->where('employee_id', $employeeId)->value('user_id');
            $user = array_merge($user, isset($options['user']) ? (array) $options['user'] : [],
                ['user_id' => $existing !== null ? $existing : (string) $user['user_id'], 'employee_id' => $employeeId]);

            try {
                if ($device->isReadOnly()) {
                    throw Exceptions\ReadOnlyDeviceException::forDevice($device->serial_number);
                }
                $steps = [app(UserService::class)->setUserStep($device, $user, 'manual', $createdBy)];
                if (!empty($options['with_biometrics'])) {
                    $steps = array_merge($steps, app(BiometricService::class)->pushSteps($device, $employeeId, (string) $user['user_id'],
                        !empty($options['force_biometrics']), 'manual', $createdBy));
                }
                $batch = app(CommandQueue::class)->batch($device, $steps, true, ['created_by' => $createdBy]);
                DeviceUser::where('device_id', $device->id)->where('user_id', (string) $user['user_id'])
                    ->update(['last_command_id' => $batch->first()->id]);
                $out[$device->serial_number] = ['batch_id' => $batch->first()->batch_id, 'commands' => $batch->pluck('id')->all()];
            } catch (\DomainException $e) {
                $out[$device->serial_number] = ['skipped' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** Enroll once, punch anywhere: user record + stored templates to other devices. */
    public function copyBiometrics($employeeId, $devices, bool $force = false, ?string $createdBy = null): array
    {
        return $this->syncEmployee($employeeId, $devices, ['with_biometrics' => true, 'force_biometrics' => $force, 'created_by' => $createdBy]);
    }

    /**
     * Remove an employee's user from devices (every device they are on when $devices is null).
     *
     * @return array per serial number: ['command_id'] or ['skipped']
     */
    public function removeEmployee($employeeId, $devices = null, ?string $createdBy = null): array
    {
        $rows = DeviceUser::where('employee_id', (string) $employeeId)->where('sync_status', '!=', 'removed')
            ->when($devices !== null, function ($q) use ($devices) {
                $q->whereIn('device_id', $this->findDevices($devices)->pluck('id')->all());
            })->get();

        $out = [];
        foreach ($rows as $row) {
            $device = Device::find($row->device_id);
            try {
                $out[$device->serial_number] = ['command_id' => app(UserService::class)->deleteUser($device, $row->user_id, 'manual', $createdBy)->id];
            } catch (\DomainException $e) {
                $out[$device->serial_number] = ['skipped' => $e->getMessage()];
            }
        }

        return $out;
    }

    public function templates($employeeId): Collection
    {
        return app(BiometricService::class)->templatesOf((string) $employeeId);
    }

    /** Base64 JPG photo read from a device, or null. */
    public function photo($employeeId): ?string
    {
        $tpl = Biometric::where('owner_key', Biometric::employeeKey((string) $employeeId))->where('type', Biometric::PHOTO)->first();

        return $tpl ? $tpl->template : null;
    }

    /** Hand waiting punches of every approved device (or one) to the PunchHandler. */
    public function processPunches($device = null): array
    {
        $devices = $device !== null ? new Collection([$this->findDevice($device)])
            : Device::approved()->where('process_punches', true)->get();
        $out = [];
        foreach ($devices as $d) {
            $out[$d->serial_number] = app(PunchProcessor::class)->processPending($d);
        }

        return $out;
    }

    public function command(int $id): ?Command
    {
        return Command::find($id);
    }

    /** Column values of a new device row, from config. */
    public static function newDeviceDefaults(string $serialNumber): array
    {
        return [
            'serial_number'       => $serialNumber,
            'name'                => 'Device ' . $serialNumber,
            'registration_status' => Device::STATUS_PENDING,
            'process_punches'     => (bool) config('amt-push.punches.process_by_default', true),
            'capture_biometrics'  => (bool) config('amt-push.biometrics.capture', true),
        ];
    }

    /** @param Device|int|string $device */
    protected function findDevice($device): Device
    {
        if ($device instanceof Device) {
            return $device;
        }
        $found = Device::where('serial_number', (string) $device)->first();
        if (!$found && is_int($device)) {
            $found = Device::find($device);
        }
        if (!$found) {
            throw new \InvalidArgumentException("AMT device [{$device}] not found.");
        }

        return $found;
    }

    protected function findDevices($devices): Collection
    {
        $list = is_array($devices) || $devices instanceof Collection ? $devices : [$devices];
        $out = new Collection();
        foreach ($list as $d) {
            $out->push($this->findDevice($d));
        }

        return $out->unique('id')->values();
    }
}
