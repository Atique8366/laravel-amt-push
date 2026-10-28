<?php

namespace BiometricPush\Adms\Services;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Contracts\EmployeeResolver;
use BiometricPush\Adms\Events\DeviceUsersUploaded;
use BiometricPush\Adms\Exceptions\ReadOnlyDeviceException;
use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceTemplate;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Support\Protocol;

/**
 * Device users: import what devices upload, link PINs to employees, write / delete users.
 *
 * Writes keep what the device already has for anything the caller leaves out (admin level,
 * password, card, time-zone setting). With users.read_first on, the device's current users
 * are read before the writes are built, so a user that exists only on the device is not
 * overwritten with blank values.
 */
class UserService
{
    public const DEFAULT_TZ = '0000000000000000';

    /** @var CommandService */
    private $commands;

    /** @var EmployeeResolver */
    private $resolver;

    public function __construct(CommandService $commands, EmployeeResolver $resolver)
    {
        $this->commands = $commands;
        $this->resolver = $resolver;
    }

    // ── Device → app ─────────────────────────────────────────────────────

    /**
     * USER lines uploaded by the device (OPERLOG / USERINFO / query reply): update known
     * users, create new ones and link them through the EmployeeResolver.
     *
     * @return Collection|DeviceUser[] rows created or updated
     */
    public function importUserLines(Device $device, array $lines): Collection
    {
        $touched = new Collection();
        // a full user read in progress: users in this upload are on the device for that read
        $readId = Command::where('device_id', $device->id)->where('type', 'query_users')
            ->where('status', Command::STATUS_SENT)->where('command', 'DATA QUERY USERINFO')->max('id');

        foreach ($lines as $line) {
            if (stripos(ltrim($line), 'USER ') !== 0) {
                continue;
            }
            $f = Protocol::tabFields(substr(ltrim($line), 5));
            $pin = trim(isset($f['PIN']) ? $f['PIN'] : '');
            if ($pin === '' || strlen($pin) > 32) {
                continue;
            }
            $password = trim(isset($f['Passwd']) ? $f['Passwd'] : '');
            $card = trim(isset($f['Card']) ? $f['Card'] : '');
            $values = [
                'name'      => Protocol::cleanText(isset($f['Name']) ? $f['Name'] : '', 100),
                'privilege' => mb_substr(trim(isset($f['Pri']) ? $f['Pri'] : '0'), 0, 8),
                'password'  => $password === '' ? null : mb_substr($password, 0, 32),
                'card'      => $card === '' || $card === '0' ? null : mb_substr($card, 0, 32),
                'seen_at'   => now(),
            ];
            if ($readId) {
                $values['last_read_id'] = (int) $readId;
            }
            // TZ (time-zone / access-group setting) is kept as the device has it
            if (isset($f['TZ']) && preg_match('/^\d{1,16}$/', trim($f['TZ']))) {
                $values['tz'] = trim($f['TZ']);
            }
            $values['sync_hash'] = self::stateHash($pin, $values['name'], (int) $values['privilege'],
                (string) $values['password'], (string) $values['card']);

            $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'pin' => $pin]);
            $row->fill($values);
            if (!$row->exists || in_array($row->sync_status, ['removed', 'pending'], true)) {
                $row->fill(['sync_status' => 'synced', 'removed_at' => null]);
            }
            $linked = false;
            if (!$row->employee_id) {
                $employeeId = $this->resolver->resolve($device, $pin, $values['name']);
                if ($employeeId !== null && $employeeId !== '' && !$this->linkedElsewhere($device, (string) $employeeId, $pin)) {
                    $row->fill(['employee_id' => (string) $employeeId, 'match_status' => 'linked']);
                    $linked = true;
                }
            }
            $row->save();
            if ($linked) {
                $this->rekeyTemplates($device, $pin, $row->employee_id);
            }
            $touched->push($row);
        }

        if ($touched->isNotEmpty()) {
            event(new DeviceUsersUploaded($device, $touched));
        }

        return $touched;
    }

    /**
     * After a full user read (DATA QUERY USERINFO) the device has uploaded every user it holds.
     * Users stored as on the device but not in that upload were deleted on the device itself:
     * mark them removed and their templates there missing. Nothing is sent from here.
     *
     * @return int users marked as removed
     */
    public function markMissingAfterFullRead(Device $device, Command $read): int
    {
        // Nothing imported during this read (e.g. the upload failed): don't guess.
        if (!DeviceUser::where('device_id', $device->id)->where('last_read_id', $read->id)->exists()) {
            return 0;
        }

        $missing = DeviceUser::where('device_id', $device->id)->where('sync_status', 'synced')
            ->where(function ($q) use ($read) {
                $q->whereNull('last_read_id')->orWhere('last_read_id', '!=', $read->id);
            })->get();

        foreach ($missing as $row) {
            $row->fill([
                'sync_status' => 'removed',
                'sync_hash'   => null,
                'removed_at'  => now(),
                'last_error'  => 'Not on the device (deleted on the device)',
            ])->save();
            DeviceTemplate::where('device_id', $device->id)->where('pin', $row->pin)
                ->whereIn('status', ['on_device', 'success'])
                ->update(['status' => 'missing', 'last_error' => 'Not on the device any more', 'updated_at' => now()]);
        }

        return $missing->count();
    }

    // ── Linking ──────────────────────────────────────────────────────────

    /**
     * Link a device PIN to an employee by hand.
     *
     * @throws \InvalidArgumentException when the employee already has another PIN on this device
     */
    public function link(Device $device, string $pin, string $employeeId): DeviceUser
    {
        $other = $this->linkedElsewhere($device, $employeeId, $pin);
        if ($other) {
            throw new \InvalidArgumentException("Employee {$employeeId} is already linked to PIN {$other->pin} on this device.");
        }
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'pin' => $pin]);
        if (!$row->exists) {
            $row->sync_status = 'pending';   // not seen on the device yet
        }
        $row->fill(['employee_id' => $employeeId, 'match_status' => 'manual'])->save();
        $this->rekeyTemplates($device, $pin, $employeeId);

        return $row;
    }

    public function unlink(Device $device, string $pin): ?DeviceUser
    {
        $row = DeviceUser::where('device_id', $device->id)->where('pin', $pin)->first();
        if ($row) {
            $row->fill(['employee_id' => null, 'match_status' => 'unmatched'])->save();
        }

        return $row;
    }

    // ── App → device ─────────────────────────────────────────────────────

    /**
     * Write users to the device.
     *
     * @param array $users   each: pin (required), name, privilege, password, card, group, employee_id, force
     * @param array $options read_first (bool, default config users.read_first), source, created_by,
     *                       templates_for (employee ids whose templates follow the users), force_templates
     * @return Collection|Command[] commands queued now (the user read when read-first applies)
     * @throws ReadOnlyDeviceException|\InvalidArgumentException
     */
    public function setUsers(Device $device, array $users, array $options = []): Collection
    {
        if ($device->isReadOnly()) {
            throw ReadOnlyDeviceException::forDevice($device->serial_number);
        }
        $users = array_values(array_map(function ($user) {
            return $this->normalizeUser((array) $user);
        }, $users));
        $readFirst = array_key_exists('read_first', $options) ? (bool) $options['read_first'] : (bool) config('adms.users.read_first', true);
        $source = isset($options['source']) ? $options['source'] : 'manual';
        $createdBy = isset($options['created_by']) ? (string) $options['created_by'] : null;

        if ($readFirst) {
            return new Collection([$this->queueReadThen($device, $users, $options, $source, $createdBy)]);
        }

        $queued = new Collection();
        foreach ($users as $user) {
            if ($cmd = $this->applyUser($device, $user, $source, $createdBy)) {
                $queued->push($cmd);
            }
        }
        if (!empty($options['templates_for'])) {
            app(BiometricService::class)->queueTemplates($device, $options['templates_for'],
                !empty($options['force_templates']), $createdBy, $source);
        }

        return $queued;
    }

    /** Read-first chain finished: build the waiting writes from what the device has now. */
    public function applyDeferred(Device $device, Command $read): void
    {
        if ($device->isReadOnly()) {
            return;
        }
        $meta = $read->meta;
        foreach ($meta['then_set_users'] as $user) {
            try {
                $this->applyUser($device, $user, $read->source, $read->created_by);
            } catch (\InvalidArgumentException $e) {
                report($e);
            }
        }
        if (!empty($meta['then_templates'])) {
            app(BiometricService::class)->queueTemplates($device, $meta['then_templates'],
                !empty($meta['force_templates']), $read->created_by, $read->source);
        }
    }

    /**
     * Queue the user record (DATA UPDATE USERINFO). Values not given keep the device's values.
     *
     * @return Command|null null when the device already has exactly this user
     */
    public function applyUser(Device $device, array $user, string $source = 'manual', ?string $createdBy = null): ?Command
    {
        $user = $this->normalizeUser($user);
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'pin' => $user['pin']]);

        if (isset($user['employee_id'])) {
            $other = $this->linkedElsewhere($device, $user['employee_id'], $user['pin']);
            if ($other && $other->isOnDevice()) {
                throw new \InvalidArgumentException("Employee {$user['employee_id']} is already on this device as PIN {$other->pin}.");
            }
            if ($other) {
                $other->fill(['employee_id' => null, 'match_status' => 'unmatched'])->save();   // moved to a new PIN
            }
            if ((string) $row->employee_id !== $user['employee_id']) {
                $row->fill(['employee_id' => $user['employee_id'], 'match_status' => 'linked']);
            }
        }

        $max = max(1, (int) ($device->name_max_length ?: config('adms.devices.name_max_length', 24)));
        $name = mb_substr(trim(Protocol::field(array_key_exists('name', $user) ? $user['name'] : $row->name)), 0, $max);
        $pri = isset($user['privilege']) ? (int) $user['privilege'] : (int) $row->privilege;
        $passwd = str_replace(' ', '', Protocol::field(array_key_exists('password', $user) ? $user['password'] : $row->password));
        $card = str_replace(' ', '', Protocol::field(array_key_exists('card', $user) ? $user['card'] : $row->card));
        $tz = preg_match('/^\d{1,16}$/', (string) $row->tz) ? (string) $row->tz : self::DEFAULT_TZ;
        $group = isset($user['group']) ? (int) $user['group'] : 1;
        $hash = self::stateHash($user['pin'], $name, $pri, $passwd, $card);

        if ($row->exists && $row->sync_status === 'synced' && $row->sync_hash === $hash && empty($user['force'])) {
            $row->save();   // link change only
            return null;
        }

        $row->fill(['sync_status' => 'queued', 'last_error' => null])->save();
        $cmd = $this->commands->queue($device, 'user_update',
            'DATA UPDATE USERINFO PIN=' . $user['pin'] . "\tName=" . $name . "\tPri=" . $pri . "\tPasswd=" . $passwd
            . "\tCard=" . $card . "\tGrp=" . $group . "\tTZ=" . $tz . "\tVerify=0",
            [
                'device_user_id' => $row->id,
                'employee_id'    => $row->employee_id,
                'meta'           => ['hash' => $hash, 'name' => $name, 'pri' => $pri,
                                     'passwd' => $passwd === '' ? null : $passwd, 'card' => $card === '' ? null : $card],
                'source'         => $source,
                'created_by'     => $createdBy,
            ]);
        $row->last_command_id = $cmd->id;
        $row->save();

        return $cmd;
    }

    /**
     * Delete a user (and with it their templates) from the device.
     * A second delete while one is waiting returns the waiting one.
     */
    public function deleteUser(Device $device, string $pin, string $source = 'manual', ?string $createdBy = null): Command
    {
        $row = DeviceUser::firstOrNew(['device_id' => $device->id, 'pin' => $pin]);
        if ($row->exists && $row->sync_status === 'removing' && $row->last_command_id) {
            $waiting = Command::whereKey($row->last_command_id)->whereIn('status', [Command::STATUS_QUEUED, Command::STATUS_SENT])->first();
            if ($waiting) {
                return $waiting;
            }
        }
        if (!$row->exists) {
            $row->save();
        }
        $cmd = $this->commands->queue($device, 'user_delete', 'DATA DELETE USERINFO PIN=' . $pin, [
            'device_user_id' => $row->id,
            'employee_id'    => $row->employee_id,
            'source'         => $source,
            'created_by'     => $createdBy,
        ]);
        $row->fill(['sync_status' => 'removing', 'last_command_id' => $cmd->id])->save();

        return $cmd;
    }

    /** Ask the device to upload all its users (or one PIN). */
    public function queryUsers(Device $device, ?string $pin = null, string $source = 'manual', ?string $createdBy = null): Command
    {
        return $this->commands->queue($device, 'query_users',
            $pin === null ? 'DATA QUERY USERINFO' : 'DATA QUERY USERINFO PIN=' . $pin,
            ['source' => $source, 'created_by' => $createdBy]);
    }

    // ─────────────────────────────────────────────────────────────────────

    public static function stateHash(string $pin, string $name, int $pri, string $passwd, string $card): string
    {
        return sha1($pin . "\x1f" . $name . "\x1f" . $pri . "\x1f" . $passwd . "\x1f" . $card);
    }

    /** One user read for all waiting writes: joins a read that has not been sent yet. */
    private function queueReadThen(Device $device, array $users, array $options, string $source, ?string $createdBy): Command
    {
        $templates = isset($options['templates_for']) ? array_values(array_map('strval', (array) $options['templates_for'])) : [];

        $waiting = Command::where('device_id', $device->id)->where('type', 'query_users')
            ->where('status', Command::STATUS_QUEUED)->where('command', 'DATA QUERY USERINFO')
            ->orderByDesc('id')->get()->first(function (Command $c) {
                return is_array($c->meta) && !empty($c->meta['then_set_users']);
            });
        if ($waiting) {
            $meta = $waiting->meta;
            $byPin = [];
            foreach (array_merge($meta['then_set_users'], $users) as $u) {
                $byPin[$u['pin']] = $u;   // the later write for a PIN wins
            }
            $meta['then_set_users'] = array_values($byPin);
            $meta['then_templates'] = array_values(array_unique(array_merge(
                isset($meta['then_templates']) ? $meta['then_templates'] : [], $templates)));
            $meta['force_templates'] = !empty($meta['force_templates']) || !empty($options['force_templates']);
            $waiting->meta = $meta;
            $waiting->save();

            return $waiting;
        }

        foreach ($users as $user) {
            DeviceUser::where('device_id', $device->id)->where('pin', $user['pin'])
                ->whereNotIn('sync_status', ['removing'])->update(['sync_status' => 'pending']);
        }

        return $this->commands->queue($device, 'query_users', 'DATA QUERY USERINFO', [
            'meta'       => ['then_set_users' => $users, 'then_templates' => $templates,
                             'force_templates' => !empty($options['force_templates'])],
            'source'     => $source,
            'created_by' => $createdBy,
        ]);
    }

    private function normalizeUser(array $user): array
    {
        $pin = trim((string) (isset($user['pin']) ? $user['pin'] : ''));
        if ($pin === '' || strlen($pin) > 32 || preg_match('/\s/', $pin)) {
            throw new \InvalidArgumentException('A device user needs a PIN of 1-32 characters without spaces.');
        }
        $user['pin'] = $pin;
        if (isset($user['employee_id'])) {
            $user['employee_id'] = (string) $user['employee_id'];
        }
        if (isset($user['privilege']) && is_string($user['privilege']) && isset(DeviceUser::ROLES[$user['privilege']])) {
            $user['privilege'] = DeviceUser::ROLES[$user['privilege']];
        }

        return $user;
    }

    private function linkedElsewhere(Device $device, string $employeeId, string $pin): ?DeviceUser
    {
        return DeviceUser::where('device_id', $device->id)->where('employee_id', $employeeId)
            ->where('pin', '!=', $pin)->first();
    }

    /** Templates captured while the PIN was unlinked now belong to the employee. */
    private function rekeyTemplates(Device $device, string $pin, string $employeeId): void
    {
        $from = BiometricTemplate::deviceUserKey($device->id, $pin);
        $to = BiometricTemplate::employeeKey($employeeId);

        foreach (BiometricTemplate::where('owner_key', $from)->get() as $tpl) {
            $same = BiometricTemplate::where('owner_key', $to)->where('bio_type', $tpl->bio_type)
                ->where('bio_no', $tpl->bio_no)->where('bio_index', $tpl->bio_index)
                ->where('major_ver', $tpl->major_ver)->first();
            if ($same) {
                foreach (DeviceTemplate::where('template_id', $tpl->id)->get() as $dt) {
                    $taken = DeviceTemplate::where('device_id', $dt->device_id)->where('template_id', $same->id)->exists();
                    $taken ? $dt->delete() : $dt->fill(['template_id' => $same->id])->save();
                }
                $tpl->delete();
            } else {
                $tpl->fill(['owner_key' => $to, 'employee_id' => $employeeId])->save();
            }
        }
    }
}
