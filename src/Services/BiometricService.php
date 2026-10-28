<?php

namespace BiometricPush\AmtPush\Services;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Events\BiometricsCaptured;
use BiometricPush\AmtPush\Models\AdminLog;
use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceBiometric;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Protocol\Frame;

/**
 * Enroll once, punch at any device.
 *
 * Capture (device → app): after an enrollment on a terminal (AdminLog_v2) the person's templates
 * are read back (GetFingerData / GetFaceData / GetPalmData) and stored once per employee
 * (per device UserID while unlinked).
 *
 * Copy (app → device): SetFingerData / SetFaceData / SetPalmData / SetUserPhoto after the user
 * record, in one batch that stops at the first failure.
 */
class BiometricService
{
    public const SET_REQUESTS = [
        Biometric::FINGER => 'SetFingerData',
        Biometric::FACE   => 'SetFaceData',
        Biometric::PALM   => 'SetPalmData',
        Biometric::PHOTO  => 'SetUserPhoto',
    ];

    /** @var CommandQueue */
    private $commands;

    public function __construct(CommandQueue $commands)
    {
        $this->commands = $commands;
    }

    // ── Read back from the device ────────────────────────────────────────

    /** An AdminLog_v2 enrollment / deletion: read that person's templates back. */
    public function onAdminLog(Device $device, AdminLog $log): int
    {
        if (!config('amt-push.biometrics.capture', true) || !config('amt-push.biometrics.pull_after_enroll', true)
            || !$device->capture_biometrics) {
            return 0;
        }
        $userId = (string) $log->user_id;
        if ($userId === '' || $userId === '0') {
            return 0;   // menu access / boot noise
        }
        $kinds = [];
        foreach ((array) config('amt-push.biometrics.enroll_actions', []) as $kind => $actions) {
            if (in_array($log->action, (array) $actions, true)) {
                $kinds[] = $kind;
            }
        }
        if (!$kinds) {
            return 0;
        }
        $log->forceFill(['handled' => true])->save();

        return $this->pull($device, $userId, $kinds, 'auto');
    }

    /**
     * Queue reads of a UserID's templates.
     *
     * @param array $kinds finger | face | palm | photo
     * @return int requests queued
     */
    public function pull(Device $device, string $userId, array $kinds = ['finger', 'face'], string $source = 'manual',
                         ?string $createdBy = null): int
    {
        $requests = [];
        if (config('amt-push.biometrics.pull_strategy', 'probe') === 'user_data' && array_intersect($kinds, ['finger', 'face', 'palm'])) {
            $requests[] = ['GetUserData', ['user_id' => $userId, 'then_pull' => array_values($kinds)]];
        } else {
            if (in_array('finger', $kinds, true)) {
                for ($slot = 0; $slot < 10; $slot++) {
                    $requests[] = ['GetFingerData', ['user_id' => $userId, 'finger_no' => $slot]];
                }
            }
            if (in_array('face', $kinds, true)) {
                $requests[] = ['GetFaceData', ['user_id' => $userId]];
            }
            if (in_array('palm', $kinds, true) || (in_array('finger', $kinds, true) && config('amt-push.biometrics.pull_palms'))) {
                foreach ([0, 1] as $slot) {
                    $requests[] = ['GetPalmData', ['user_id' => $userId, 'palm_no' => $slot]];
                }
            }
        }
        if (in_array('photo', $kinds, true)) {
            $requests[] = ['GetUserPhoto', ['user_id' => $userId]];
        }

        return $this->queueReads($device, $requests, $source, $createdBy);
    }

    /** GetUserData told us what is enrolled: read only those (user_data strategy). */
    public function pullEnrolled(Device $device, DeviceUser $user, array $kinds, ?Command $origin = null): int
    {
        $requests = [];
        if (in_array('finger', $kinds, true)) {
            foreach ($user->enrolledFingers() as $slot) {
                $requests[] = ['GetFingerData', ['user_id' => $user->user_id, 'finger_no' => $slot]];
            }
        }
        if (in_array('face', $kinds, true) && $user->face_enrolled) {
            $requests[] = ['GetFaceData', ['user_id' => $user->user_id]];
        }
        if (in_array('palm', $kinds, true)) {
            foreach ($user->enrolledPalms() as $slot) {
                $requests[] = ['GetPalmData', ['user_id' => $user->user_id, 'palm_no' => $slot]];
            }
        }

        return $this->queueReads($device, $requests, $origin ? $origin->source : 'auto', $origin ? $origin->created_by : null);
    }

    /** A Get*Data answer: store the template, or note that the slot is empty on the device. */
    public function onGetResponse(Device $device, Command $cmd, Frame $frame): void
    {
        $p = (array) $cmd->payload;
        $userId = isset($p['user_id']) ? (string) $p['user_id'] : $frame->get('UserID');
        $map = [
            'GetFingerData' => [Biometric::FINGER, 'FingerData', isset($p['finger_no']) ? (int) $p['finger_no'] : $frame->int('FingerNo')],
            'GetFaceData'   => [Biometric::FACE, 'FaceData', 0],
            'GetPalmData'   => [Biometric::PALM, 'PalmData', isset($p['palm_no']) ? (int) $p['palm_no'] : $frame->int('PalmNo')],
            'GetUserPhoto'  => [Biometric::PHOTO, 'PhotoData', 0],
        ];
        list($type, $tag, $slot) = $map[$cmd->request];
        $data = $frame->get($tag);

        if ($data !== '' && preg_match('#^[A-Za-z0-9+/=\s]+$#', $data)) {
            $duress = in_array(strtolower($frame->get('Duress')), ['yes', '1'], true);
            $tpl = $this->store($device, $userId, $type, (int) $slot, preg_replace('/\s+/', '', $data), $duress);
            if ($tpl) {
                event(new BiometricsCaptured($device, new Collection([$tpl])));
            }
        } else {
            $this->markEmptyOnDevice($device, $userId, $type, (int) $slot);
        }
    }

    /** @return Biometric|null the template when new or changed */
    public function store(Device $device, string $userId, string $type, int $slot, string $template, bool $duress = false): ?Biometric
    {
        $employeeId = app(UserService::class)->linkFor($device, $userId)->employee_id;
        $owner = $employeeId !== null ? Biometric::employeeKey((string) $employeeId) : Biometric::deviceUserKey($device->id, $userId);
        $hash = sha1($template);

        $tpl = Biometric::firstOrNew(['owner_key' => $owner, 'type' => $type, 'slot' => $slot]);
        $changed = !$tpl->exists || $tpl->template_hash !== $hash || (bool) $tpl->duress !== $duress;
        if ($changed) {
            $tpl->fill([
                'employee_id'      => $employeeId !== null ? (string) $employeeId : null,
                'duress'           => $duress,
                'template'         => $template,
                'template_hash'    => $hash,
                'source_device_id' => $device->id,
                'source_user_id'   => $userId,
                'captured_at'      => now(),
            ])->save();
        }
        // this version is on the device it came from: never sent back there
        DeviceBiometric::updateOrCreate(
            ['device_id' => $device->id, 'biometric_id' => $tpl->id],
            ['user_id' => $userId, 'template_hash' => $hash, 'status' => 'on_device', 'last_error' => null, 'synced_at' => now()]
        );

        return $changed ? $tpl : null;
    }

    /**
     * The device has no template in this slot. Its copy is marked missing; the stored template is
     * deleted only when it came from this device and no other device holds it.
     */
    public function markEmptyOnDevice(Device $device, string $userId, string $type, int $slot): void
    {
        $employeeId = DeviceUser::where('device_id', $device->id)->where('user_id', $userId)->value('employee_id');
        $owner = $employeeId !== null ? Biometric::employeeKey((string) $employeeId) : Biometric::deviceUserKey($device->id, $userId);
        $tpl = Biometric::where('owner_key', $owner)->where('type', $type)->where('slot', $slot)->first();
        if (!$tpl) {
            return;
        }
        DeviceBiometric::where('device_id', $device->id)->where('biometric_id', $tpl->id)
            ->update(['status' => 'missing', 'last_error' => 'Not on the device', 'updated_at' => now()]);

        $elsewhere = DeviceBiometric::where('biometric_id', $tpl->id)->where('device_id', '!=', $device->id)
            ->whereIn('status', ['on_device', 'success'])->exists();
        if ((int) $tpl->source_device_id === (int) $device->id && !$elsewhere) {
            DeviceBiometric::where('biometric_id', $tpl->id)->delete();
            $tpl->delete();
        }
    }

    // ── Copy to devices ──────────────────────────────────────────────────

    public function templatesOf(string $employeeId): Collection
    {
        return Biometric::where('owner_key', Biometric::employeeKey($employeeId))
            ->orderByRaw("CASE type WHEN 'finger' THEN 1 WHEN 'palm' THEN 2 WHEN 'face' THEN 3 ELSE 4 END")
            ->orderBy('slot')->get();
    }

    /**
     * Batch steps that write an employee's templates to a device under the given UserID
     * (only changed ones unless $force).
     *
     * @return array [request, payload, attrs][]
     */
    public function pushSteps(Device $device, string $employeeId, string $userId, bool $force = false, string $source = 'manual',
                              ?string $createdBy = null): array
    {
        $privilege = DeviceUser::where('device_id', $device->id)->where('user_id', $userId)->value('privilege')
            ?: config('amt-push.users.default_privilege', 'User');
        $steps = [];
        foreach ($this->templatesOf($employeeId) as $tpl) {
            $db = DeviceBiometric::firstOrNew(['device_id' => $device->id, 'biometric_id' => $tpl->id]);
            if (!$force && $db->exists && $db->template_hash === $tpl->template_hash && $db->user_id === $userId
                && in_array($db->status, ['on_device', 'success'], true)) {
                continue;
            }
            $db->fill(['user_id' => $userId, 'template_hash' => $tpl->template_hash, 'status' => 'queued', 'last_error' => null])->save();
            $steps[] = [self::SET_REQUESTS[$tpl->type], [
                'user_id' => $userId, 'biometric_id' => $tpl->id, 'privilege' => $privilege, 'device_biometric_id' => $db->id,
            ], ['employee_id' => $employeeId, 'source' => $source, 'created_by' => $createdBy]];
        }

        return $steps;
    }

    /** A Set*Data finished (or was aborted / cancelled). */
    public function afterSet(Command $cmd): void
    {
        $p = (array) $cmd->payload;
        $db = isset($p['device_biometric_id']) ? DeviceBiometric::find($p['device_biometric_id']) : null;
        if (!$db) {
            return;
        }
        if ($cmd->succeeded()) {
            $db->fill(['status' => 'success', 'last_error' => null, 'synced_at' => now(), 'last_command_id' => $cmd->id])->save();
        } elseif ($cmd->isFinished()) {
            $db->fill(['status' => 'failed', 'last_error' => $cmd->error, 'last_command_id' => $cmd->id])->save();
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function queueReads(Device $device, array $requests, string $source, ?string $createdBy): int
    {
        $queued = 0;
        foreach ($requests as $r) {
            if ($this->commands->waiting($device, $r[0], $r[1])) {
                continue;
            }
            $this->commands->queue($device, $r[0], $r[1], ['source' => $source, 'created_by' => $createdBy]);
            $queued++;
        }

        return $queued;
    }
}
