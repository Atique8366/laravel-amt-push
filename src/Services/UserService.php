<?php

namespace BiometricPush\AmtPush\Services;

use Illuminate\Support\Str;
use BiometricPush\AmtPush\Contracts\EmployeeResolver;
use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceBiometric;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Protocol\Frame;

/**
 * Device users: link UserIDs to employees, write / delete users, read the device's user list.
 *
 * SetUserData only carries the fields that are given: anything left out (card, password, QR,
 * privilege, access time sets) stays as it is on the device.
 */
class UserService
{
    /** @var CommandQueue */
    private $commands;

    /** @var EmployeeResolver */
    private $resolver;

    public function __construct(CommandQueue $commands, EmployeeResolver $resolver)
    {
        $this->commands = $commands;
        $this->resolver = $resolver;
    }

    /** The device-user row of a UserID, created and linked (through the resolver) when new or unlinked. */
    public function linkFor(Device $device, string $userId, string $name = ''): DeviceUser
    {
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'user_id' => $userId]);
        $linked = false;
        if (!$row->employee_id) {
            $employeeId = $this->resolver->resolve($device, $userId, $name !== '' ? $name : (string) $row->name);
            if ($employeeId !== null && $employeeId !== '' && !$this->linkedElsewhere($device, (string) $employeeId, $userId)) {
                $row->fill(['employee_id' => (string) $employeeId, 'match_status' => 'linked']);
                $linked = true;
            }
        }
        if ($row->isDirty() || !$row->exists) {
            $row->save();
        }
        if ($linked) {
            $this->rekeyBiometrics($device, $userId, $row->employee_id);
        }

        return $row;
    }

    /** @throws \InvalidArgumentException when the employee already has another UserID on this device */
    public function link(Device $device, string $userId, string $employeeId): DeviceUser
    {
        $other = $this->linkedElsewhere($device, $employeeId, $userId);
        if ($other) {
            throw new \InvalidArgumentException("Employee {$employeeId} is already linked to UserID {$other->user_id} on this device.");
        }
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'user_id' => $userId]);
        if (!$row->exists) {
            $row->sync_status = 'pending';
        }
        $row->fill(['employee_id' => $employeeId, 'match_status' => 'manual'])->save();
        $this->rekeyBiometrics($device, $userId, $employeeId);

        return $row;
    }

    public function unlink(Device $device, string $userId): ?DeviceUser
    {
        $row = DeviceUser::where('device_id', $device->id)->where('user_id', $userId)->first();
        if ($row) {
            $row->fill(['employee_id' => null, 'match_status' => 'unmatched'])->save();
        }

        return $row;
    }

    /**
     * SetUserData payload for a user; the keys that are present are written.
     *
     * @param array $user user_id (required), name, privilege, department, enabled, password, card, qr,
     *                    timesets [5 ints 0-49], period [start, end], allow_no_certificate, employee_id
     */
    public function userPayload(Device $device, array $user): array
    {
        $userId = trim((string) (isset($user['user_id']) ? $user['user_id'] : ''));
        if ($userId === '' || !preg_match('/^\d{1,8}$/', $userId) || (int) $userId < 1) {
            throw new \InvalidArgumentException('A device UserID must be a number from 1 to 99999999.');
        }
        if (isset($user['privilege']) && !in_array($user['privilege'], DeviceUser::PRIVILEGES, true)) {
            throw new \InvalidArgumentException('Privilege must be one of: ' . implode(', ', DeviceUser::PRIVILEGES));
        }
        $payload = ['user_id' => $userId, 'type' => 'Set'];
        if (array_key_exists('name', $user) && $user['name'] !== null) {
            $max = max(1, (int) config('amt-push.users.name_max_length', 24));
            $payload['name'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $user['name'])), 0, $max);
        }
        foreach (['privilege', 'department', 'enabled', 'password', 'card', 'qr', 'timesets', 'period'] as $key) {
            if (array_key_exists($key, $user)) {
                $payload[$key] = $user[$key];
            }
        }
        $payload['allow_no_certificate'] = array_key_exists('allow_no_certificate', $user)
            ? $user['allow_no_certificate'] : (bool) config('amt-push.users.allow_no_certificate', true);

        return $payload;
    }

    /** The [request, payload, attrs] step that writes a user (for batches). */
    public function setUserStep(Device $device, array $user, string $source = 'manual', ?string $createdBy = null): array
    {
        $payload = $this->userPayload($device, $user);
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'user_id' => $payload['user_id']]);
        if (isset($user['employee_id'])) {
            $employeeId = (string) $user['employee_id'];
            $other = $this->linkedElsewhere($device, $employeeId, $payload['user_id']);
            if ($other && $other->isOnDevice()) {
                throw new \InvalidArgumentException("Employee {$employeeId} is already on this device as UserID {$other->user_id}.");
            }
            if ($other) {
                $other->fill(['employee_id' => null, 'match_status' => 'unmatched'])->save();
            }
            if ((string) $row->employee_id !== $employeeId) {
                $row->fill(['employee_id' => $employeeId, 'match_status' => 'linked']);
            }
        }
        $row->fill(['sync_status' => 'queued', 'last_error' => null])->save();

        return ['SetUserData', $payload, [
            'employee_id' => $row->employee_id, 'source' => $source, 'created_by' => $createdBy,
        ]];
    }

    public function setUser(Device $device, array $user, string $source = 'manual', ?string $createdBy = null): Command
    {
        list($request, $payload, $attrs) = $this->setUserStep($device, $user, $source, $createdBy);
        $cmd = $this->commands->queue($device, $request, $payload, $attrs);
        DeviceUser::where('device_id', $device->id)->where('user_id', $payload['user_id'])->update(['last_command_id' => $cmd->id]);

        return $cmd;
    }

    /** Delete a user (with their templates) from the device. A second delete returns the waiting one. */
    public function deleteUser(Device $device, string $userId, string $source = 'manual', ?string $createdBy = null): Command
    {
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'user_id' => $userId]);
        if ($row->exists && $row->sync_status === 'removing' && $row->last_command_id) {
            $waiting = Command::whereKey($row->last_command_id)->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_SENT])->first();
            if ($waiting) {
                return $waiting;
            }
        }
        $cmd = $this->commands->queue($device, 'SetUserData', ['user_id' => $userId, 'type' => 'Delete'], [
            'employee_id' => $row->employee_id, 'source' => $source, 'created_by' => $createdBy,
        ]);
        $row->fill(['sync_status' => 'removing', 'last_command_id' => $cmd->id])->save();

        return $cmd;
    }

    /** Read every user on the device (GetFirstUserData, then GetNextUserData while More=Yes). */
    public function readAll(Device $device, string $source = 'manual', ?string $createdBy = null): Command
    {
        return $this->commands->queue($device, 'GetFirstUserData', ['read_id' => (string) Str::uuid()], [
            'source' => $source, 'created_by' => $createdBy,
        ]);
    }

    /** One user record from GetUserData / GetFirstUserData / GetNextUserData. */
    public function importFrame(Device $device, Frame $frame, ?string $readId = null): ?DeviceUser
    {
        $userId = $frame->get('UserID');
        if ($userId === '') {
            return null;
        }
        $name = $frame->has('Name') ? mb_substr(Encoding::fromUtf16($frame->get('Name')), 0, 100) : null;
        $row = $this->linkFor($device, mb_substr($userId, 0, 32), (string) $name);
        $values = array_filter([
            'name'          => $name,
            'privilege'     => $frame->opt('Privilege'),
            'department'    => $frame->int('Depart'),
            'enabled'       => $frame->has('Enabled') ? $frame->yes('Enabled') : null,
            'fingers_mask'  => $this->mask($frame->get('Fingers')),
            'palms_mask'    => $this->mask($frame->get('Palms')),
            'face_enrolled' => $frame->has('FaceEnrolled') ? $frame->yes('FaceEnrolled') : null,
            'last_read_id'  => $readId,
        ], function ($v) {
            return $v !== null;
        });
        $row->fill($values + ['seen_at' => now()]);
        if (in_array($row->sync_status, ['removed', 'pending'], true)) {
            $row->fill(['sync_status' => 'synced', 'removed_at' => null]);
        }
        $row->save();

        return $row;
    }

    /**
     * A full user read finished: users stored as on the device but not in it were deleted on
     * the device itself. Marks them removed and their templates there missing.
     */
    public function markMissingAfterRead(Device $device, string $readId): int
    {
        if (!DeviceUser::where('device_id', $device->id)->where('last_read_id', $readId)->exists()) {
            return 0;   // nothing came back: don't guess
        }
        $missing = DeviceUser::where('device_id', $device->id)->where('sync_status', 'synced')
            ->where(function ($q) use ($readId) {
                $q->whereNull('last_read_id')->orWhere('last_read_id', '!=', $readId);
            })->get();
        foreach ($missing as $row) {
            $this->markRemoved($device, $row, 'Not on the device (deleted on the device)');
        }

        return $missing->count();
    }

    public function markRemoved(Device $device, DeviceUser $row, ?string $note = null): void
    {
        $row->fill(['sync_status' => 'removed', 'removed_at' => now(), 'last_error' => $note])->save();
        DeviceBiometric::where('device_id', $device->id)->where('user_id', $row->user_id)
            ->whereIn('status', ['on_device', 'success'])
            ->update(['status' => 'missing', 'last_error' => 'User removed from the device', 'updated_at' => now()]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function linkedElsewhere(Device $device, string $employeeId, string $userId): ?DeviceUser
    {
        return DeviceUser::where('device_id', $device->id)->where('employee_id', $employeeId)
            ->where('user_id', '!=', $userId)->first();
    }

    /** "1023" (decimal) or "0x3ff" → int; '' → null. */
    private function mask(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (stripos($value, '0x') === 0) {
            return (int) hexdec(substr($value, 2));
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /** Templates captured while the UserID was unlinked now belong to the employee. */
    private function rekeyBiometrics(Device $device, string $userId, string $employeeId): void
    {
        $to = Biometric::employeeKey($employeeId);
        foreach (Biometric::where('owner_key', Biometric::deviceUserKey($device->id, $userId))->get() as $tpl) {
            $same = Biometric::where('owner_key', $to)->where('type', $tpl->type)->where('slot', $tpl->slot)->first();
            if ($same) {
                foreach (DeviceBiometric::where('biometric_id', $tpl->id)->get() as $db) {
                    $taken = DeviceBiometric::where('device_id', $db->device_id)->where('biometric_id', $same->id)->exists();
                    $taken ? $db->delete() : $db->fill(['biometric_id' => $same->id])->save();
                }
                $tpl->delete();
            } else {
                $tpl->fill(['owner_key' => $to, 'employee_id' => $employeeId])->save();
            }
        }
    }
}
