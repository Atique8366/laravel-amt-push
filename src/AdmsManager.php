<?php

namespace BiometricPush\Adms;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use BiometricPush\Adms\Contracts\EmployeeResolver;
use BiometricPush\Adms\Http\IclockController;
use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Services\BiometricService;
use BiometricPush\Adms\Services\PunchProcessor;
use BiometricPush\Adms\Services\UserService;

/**
 * Entry point of the library (the Adms facade):
 *
 *   Adms::device('SN123')->setUser('1001', 'Jane Doe');
 *   Adms::syncEmployee(42, ['SN123', 'SN456']);
 *   Adms::copyBiometrics(42, ['SN456']);
 */
class AdmsManager
{
    /**
     * A device by serial number, id or model.
     *
     * @param Device|int|string $device
     * @throws \InvalidArgumentException when it does not exist
     */
    public function device($device): DeviceClient
    {
        return new DeviceClient($this->findDevice($device));
    }

    /** Register a device ahead of its first connection (approved unless $approved = false). */
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
     * Put an employee on devices (user record from the EmployeeResolver), optionally with
     * their stored templates. Devices where the resolver returns null are skipped.
     *
     * @param array|Device|int|string $devices
     * @param array $options with_biometrics (bool), force_biometrics, read_first, created_by,
     *                       user (array: values that override the resolver's, e.g. privilege)
     * @return array per serial number: queued command id, or the reason it was skipped
     */
    public function syncEmployee($employeeId, $devices, array $options = []): array
    {
        $employeeId = (string) $employeeId;
        $resolver = app(EmployeeResolver::class);
        $out = [];

        foreach ($this->findDevices($devices) as $device) {
            $user = $resolver->deviceUser($device, $employeeId);
            if (!$user || empty($user['pin'])) {
                $out[$device->serial_number] = ['skipped' => 'resolver returned no device user'];
                continue;
            }
            // an employee keeps the PIN they already have on this device
            $existing = DeviceUser::where('device_id', $device->id)->where('employee_id', $employeeId)->value('pin');
            $user = array_merge($user, isset($options['user']) ? (array) $options['user'] : [],
                ['pin' => $existing !== null ? $existing : (string) $user['pin'], 'employee_id' => $employeeId]);

            $sync = ['source' => 'manual', 'created_by' => isset($options['created_by']) ? $options['created_by'] : null];
            if (array_key_exists('read_first', $options)) {
                $sync['read_first'] = (bool) $options['read_first'];
            }
            if (!empty($options['with_biometrics'])) {
                $sync['templates_for'] = [$employeeId];
                $sync['force_templates'] = !empty($options['force_biometrics']);
            }
            try {
                $cmd = app(UserService::class)->setUsers($device, [$user], $sync)->first();
                $out[$device->serial_number] = ['command_id' => $cmd ? $cmd->id : null];
            } catch (\DomainException $e) {
                $out[$device->serial_number] = ['skipped' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Remove an employee's user from devices (all devices they are on when $devices is null).
     *
     * @return array per serial number: queued command id
     */
    public function removeEmployee($employeeId, $devices = null, ?string $createdBy = null): array
    {
        $rows = DeviceUser::where('employee_id', (string) $employeeId)->whereNotIn('sync_status', ['removed'])
            ->when($devices !== null, function ($q) use ($devices) {
                $q->whereIn('device_id', $this->findDevices($devices)->pluck('id')->all());
            })->get();

        $out = [];
        foreach ($rows as $row) {
            $device = Device::find($row->device_id);
            try {
                $out[$device->serial_number] = ['command_id' => app(UserService::class)
                    ->deleteUser($device, $row->pin, 'manual', $createdBy)->id];
            } catch (\DomainException $e) {
                $out[$device->serial_number] = ['skipped' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** Enroll once, punch anywhere: user record + stored templates to other devices. */
    public function copyBiometrics($employeeId, $devices, bool $force = false, ?string $createdBy = null): array
    {
        return $this->syncEmployee($employeeId, $devices, [
            'with_biometrics' => true, 'force_biometrics' => $force, 'created_by' => $createdBy,
        ]);
    }

    /** Stored templates (and photo) of an employee; the raw template is hidden unless asked for. */
    public function templates($employeeId): Collection
    {
        return app(BiometricService::class)->templatesOf((string) $employeeId);
    }

    /** Base64 JPG photo captured from a device, or null. */
    public function photo($employeeId): ?string
    {
        $tpl = BiometricTemplate::where('owner_key', BiometricTemplate::employeeKey((string) $employeeId))
            ->where('bio_type', BiometricTemplate::TYPE_PHOTO)->orderByDesc('captured_at')->first();

        return $tpl ? $tpl->template : null;
    }

    /** Hand waiting punches of every approved device (or one) to the PunchHandler. */
    public function processPunches($device = null): array
    {
        $processor = app(PunchProcessor::class);
        $devices = $device !== null ? new Collection([$this->findDevice($device)])
            : Device::approved()->where('process_punches', true)->get();

        $out = [];
        foreach ($devices as $d) {
            $out[$d->serial_number] = $processor->processPending($d);
        }

        return $out;
    }

    public function command(int $id): ?Command
    {
        return Command::find($id);
    }

    /**
     * Register the /iclock device endpoints yourself (with iclock.enabled = false), e.g. in
     * routes/web.php inside your own group. Never put them behind auth / CSRF middleware.
     */
    public function routes(): void
    {
        $controller = IclockController::class;
        foreach (['cdata', 'getrequest', 'devicecmd'] as $action) {
            Route::match(['get', 'post'], 'iclock/' . $action, [$controller, $action]);
            Route::match(['get', 'post'], 'iclock/' . $action . '.aspx', [$controller, $action]);
        }
        Route::match(['get', 'post'], 'iclock/ping', [$controller, 'ping']);
        // anything else under /iclock is logged and acknowledged instead of a 404
        Route::any('iclock/{any}', [$controller, 'fallback'])->where('any', '.*');
    }

    // ─────────────────────────────────────────────────────────────────────

    /** Column values of a device row created for a serial number, from config. */
    public static function newDeviceDefaults(string $serialNumber): array
    {
        $c = config('adms.devices');

        return [
            'serial_number'       => $serialNumber,
            'name'                => 'Device ' . $serialNumber,
            'registration_status' => Device::STATUS_PENDING,
            'push_enabled'        => true,
            'process_punches'     => (bool) config('adms.punches.process_by_default', true),
            'capture_biometrics'  => (bool) config('adms.biometrics.capture', true),
            'delay_seconds'       => (int) $c['delay_seconds'],
            'error_delay_seconds' => (int) $c['error_delay_seconds'],
            'realtime'            => (bool) $c['realtime'],
            'trans_interval'      => (int) $c['trans_interval'],
            'trans_times'         => (string) $c['trans_times'],
            'trans_flag'          => $c['trans_flag'],
            'timezone_offset'     => $c['timezone_offset'],
            'name_max_length'     => (int) $c['name_max_length'],
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
            throw new \InvalidArgumentException("ADMS device [{$device}] not found.");
        }

        return $found;
    }

    /** @param array|Device|int|string $devices */
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
