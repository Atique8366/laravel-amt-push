<?php

namespace BiometricPush\Adms\Services;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Events\BiometricsCaptured;
use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceTemplate;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Support\Protocol;

/**
 * Enroll once, punch at any device.
 *
 * Capture (device → app): template lines a device uploads (FP / FACE / BIODATA / USERPIC) are
 * stored once per person: per employee for linked PINs, per device user otherwise. After
 * someone enrolls on a device, their templates are read back, because some firmware only
 * reports the enrollment event.
 *
 * Copy (app → device): the person's user record must be on the device first, then each
 * compatible template is sent. Templates only go to a device whose algorithm matches.
 */
class BiometricService
{
    /** OPLOG operation codes after which the person's templates are read back. */
    public const ENROLL_OPS = [
        6   => 'enroll fingerprint',
        31  => 'change fingerprint',
        101 => 'enroll face',
        114 => 'change face',
        124 => 'enroll palm',
        125 => 'change palm',
    ];

    /** @var CommandService */
    private $commands;

    public function __construct(CommandService $commands)
    {
        $this->commands = $commands;
    }

    // ── Capture ──────────────────────────────────────────────────────────

    /**
     * Store the template lines of an upload (OPERLOG / USERINFO / BIODATA / FINGERTMP / FACE / USERPIC).
     *
     * @return array counts: stored, updated, unchanged, skipped
     */
    public function captureLines(Device $device, array $lines, ?string $table = null): array
    {
        $counts = ['stored' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $changed = new Collection();

        foreach ($lines as $line) {
            $t = $this->parseTemplateLine($device, ltrim($line), $table);
            if ($t === null) {
                continue;
            }
            if ($t === false) {
                $counts['skipped']++;
                continue;
            }

            $employeeId = DeviceUser::where('device_id', $device->id)->where('pin', $t['pin'])->value('employee_id');
            $owner = $employeeId !== null
                ? BiometricTemplate::employeeKey((string) $employeeId)
                : BiometricTemplate::deviceUserKey($device->id, $t['pin']);

            $hash = sha1($t['template']);
            $key = [
                'owner_key' => $owner,
                'bio_type'  => $t['bio_type'],
                'bio_no'    => $t['bio_no'],
                'bio_index' => $t['bio_index'],
                'major_ver' => $t['major_ver'],
            ];
            $tpl = BiometricTemplate::where($key)->first();
            if ($tpl && $tpl->template_hash === $hash) {
                $counts['unchanged']++;
            } else {
                $values = [
                    'employee_id'      => $employeeId !== null ? (string) $employeeId : null,
                    'minor_ver'        => $t['minor_ver'],
                    'format'           => $t['format'],
                    'duress'           => $t['duress'],
                    'size'             => $t['size'],
                    'template'         => $t['template'],
                    'template_hash'    => $hash,
                    'source_format'    => $t['source_format'],
                    'source_device_id' => $device->id,
                    'source_pin'       => $t['pin'],
                    'captured_at'      => now(),
                ];
                if ($tpl) {
                    $tpl->fill($values)->save();
                    $counts['updated']++;
                } else {
                    $tpl = BiometricTemplate::create($key + $values);
                    $counts['stored']++;
                }
                $changed->push($tpl);
            }

            // This version is on the device it came from: never sent back there.
            DeviceTemplate::updateOrCreate(
                ['device_id' => $device->id, 'template_id' => $tpl->id],
                ['pin' => $t['pin'], 'template_hash' => $hash, 'status' => 'on_device', 'last_error' => null, 'synced_at' => now()]
            );
        }

        if ($changed->isNotEmpty()) {
            event(new BiometricsCaptured($device, $changed));
        }

        return $counts;
    }

    /**
     * One template line → normalized array; null = not a template line; false = unusable.
     *   FP PIN=645\tFID=5\tSize=1460\tValid=1\tTMP=...              (fingerprint, algorithm = device FPVersion)
     *   FACE PIN=1\tFID=0\tSIZE=..\tVALID=1\tTMP=...                 (face, algorithm = device FaceVersion)
     *   BIODATA Pin=1\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=1\tMajorVer=12\tMinorVer=0\tFormat=0\tTmp=...
     *   USERPIC PIN=191\tFileName=191.jpg\tSize=9232\tContent=...  (user photo; table=USERPIC sends "USER PIN=..")
     *
     * @return array|false|null
     */
    public function parseTemplateLine(Device $device, string $line, ?string $table = null)
    {
        $table = strtoupper((string) $table);
        $kind = null;
        foreach (['FP ' => 'FINGERTMP', 'FACE ' => 'FACE', 'BIODATA ' => 'BIODATA', 'USERPIC ' => 'USERPIC'] as $prefix => $k) {
            if (stripos($line, $prefix) === 0) {
                $kind = $k;
                $line = substr($line, strlen($prefix));
                break;
            }
        }
        if ($kind === null && $table === 'USERPIC' && stripos($line, 'USER ') === 0) {
            $kind = 'USERPIC';
            $line = substr($line, 5);
        }
        if ($kind === null) {
            if (!in_array($table, ['FINGERTMP', 'FACE', 'BIODATA', 'USERPIC'], true) || stripos($line, 'PIN=') !== 0) {
                return null;
            }
            $kind = $table;
        }

        $f = array_change_key_case(Protocol::tabFields($line), CASE_LOWER);
        $pin = trim(isset($f['pin']) ? $f['pin'] : '');
        $tmpKey = $kind === 'USERPIC' ? 'content' : 'tmp';
        $tmp = trim(isset($f[$tmpKey]) ? $f[$tmpKey] : '');
        if ($pin === '' || strlen($pin) > 32 || $tmp === '' || !preg_match('#^[A-Za-z0-9+/=]+$#', $tmp)) {
            return false;
        }
        $valid = (int) (isset($f['valid']) ? $f['valid'] : 1);

        if ($kind === 'USERPIC') {
            if (strlen($tmp) > (int) config('adms.biometrics.max_photo_base64', 500000)) {
                return false;
            }
            return [
                'pin' => $pin, 'bio_type' => BiometricTemplate::TYPE_PHOTO, 'bio_no' => 0, 'bio_index' => 0,
                'major_ver' => '0', 'minor_ver' => null, 'format' => 0, 'duress' => false,
                'size' => strlen($tmp), 'template' => $tmp, 'source_format' => 'USERPIC',
            ];
        }
        if ($valid === 0) {
            return false;   // disabled template
        }

        if ($kind === 'FINGERTMP') {
            $type = BiometricTemplate::TYPE_FINGERPRINT;
            $no = (int) (isset($f['fid']) ? $f['fid'] : 0);
            $index = 0;
            $major = (string) $device->fp_algorithm;
            $minor = null;
            $format = 0;
            $duress = $valid === 3;
        } elseif ($kind === 'FACE') {
            $type = BiometricTemplate::TYPE_FACE;
            $no = 0;
            $index = (int) (isset($f['fid']) ? $f['fid'] : 0);
            $major = (string) $device->face_algorithm;
            $minor = null;
            $format = 0;
            $duress = false;
        } else {
            $type = (int) (isset($f['type']) ? $f['type'] : 0);
            $no = (int) (isset($f['no']) ? $f['no'] : 0);
            $index = (int) (isset($f['index']) ? $f['index'] : 0);
            $major = trim((string) (isset($f['majorver']) ? $f['majorver'] : ''));
            $minor = trim((string) (isset($f['minorver']) ? $f['minorver'] : ''));
            $minor = $minor === '' ? null : mb_substr($minor, 0, 16);
            $format = (int) (isset($f['format']) ? $f['format'] : 0);
            $duress = (int) (isset($f['duress']) ? $f['duress'] : 0) === 1;
        }

        $major = $this->majorPart($major);
        if ($type <= 0 || $major === '') {
            return false;   // unknown algorithm: can't be copied safely
        }

        return [
            'pin' => $pin, 'bio_type' => $type, 'bio_no' => $no, 'bio_index' => $index,
            'major_ver' => $major, 'minor_ver' => $minor, 'format' => $format, 'duress' => $duress,
            'size' => strlen($tmp), 'template' => $tmp, 'source_format' => $kind,
        ];
    }

    /**
     * OPLOG lines: after an enrollment on the device, read that person's templates back.
     * "OPLOG <op>\t<admin>\t<time>\t<user pin>\t<finger>\t..." (read-only queries).
     *
     * @return int queries queued
     */
    public function onOperationLog(Device $device, array $lines): int
    {
        $pins = [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            if (stripos($line, 'OPLOG ') !== 0) {
                continue;
            }
            $f = explode("\t", substr($line, 6));
            $op = (int) trim(isset($f[0]) ? $f[0] : '');
            $pin = trim(isset($f[3]) ? $f[3] : '');
            if (isset(self::ENROLL_OPS[$op]) && $pin !== '' && $pin !== '0') {
                $pins[$pin] = true;
            }
        }

        $queued = 0;
        foreach (array_keys($pins) as $pin) {
            $queued += $this->queueQueries($device, (string) $pin, 'auto', null);
        }

        return $queued;
    }

    /**
     * Ask a device for the templates it holds: the given PINs, or every user on it.
     *
     * @return int queries queued
     */
    public function pull(Device $device, ?array $pins = null, ?string $createdBy = null): int
    {
        if ($pins === null) {
            $pins = DeviceUser::where('device_id', $device->id)->whereNotIn('sync_status', ['removed', 'removing'])
                ->pluck('pin')->all();
        }
        $queued = 0;
        foreach (array_unique(array_map('strval', $pins)) as $pin) {
            $queued += $this->queueQueries($device, $pin, 'manual', $createdBy);
        }

        return $queued;
    }

    /** Query commands this device understands for one PIN. */
    public function queryCommands(Device $device, string $pin): array
    {
        $out = [];
        if ($this->supportsBioData($device)) {
            foreach ($this->deviceBioTypes($device) as $type) {
                $out[] = "DATA QUERY BIODATA Type={$type}\tPIN={$pin}";
            }
            return $out;
        }
        if ($device->fp_algorithm) {
            $out[] = "DATA QUERY FINGERTMP PIN={$pin}";
        }
        if ($device->face_algorithm) {
            $out[] = "DATA QUERY FACE PIN={$pin}";
        }

        return $out;
    }

    private function queueQueries(Device $device, string $pin, string $source, ?string $createdBy): int
    {
        $user = DeviceUser::where('device_id', $device->id)->where('pin', $pin)->first();
        $queued = 0;
        foreach ($this->queryCommands($device, $pin) as $command) {
            $waiting = Command::where('device_id', $device->id)->where('type', 'bio_query')
                ->where('command', $command)->whereIn('status', [Command::STATUS_QUEUED, Command::STATUS_SENT])->exists();
            if ($waiting) {
                continue;
            }
            $this->commands->queue($device, 'bio_query', $command, [
                'device_user_id' => $user ? $user->id : null,
                'employee_id'    => $user ? $user->employee_id : null,
                'source'         => $source,
                'created_by'     => $createdBy,
            ]);
            $queued++;
        }

        return $queued;
    }

    // ── Copy ─────────────────────────────────────────────────────────────

    /** Stored templates of an employee. */
    public function templatesOf(string $employeeId): Collection
    {
        return BiometricTemplate::where('owner_key', BiometricTemplate::employeeKey($employeeId))
            ->orderBy('bio_type')->orderBy('bio_no')->orderBy('bio_index')->get();
    }

    /**
     * Templates of an employee that this device can take, and the ones it can't (with the reason).
     *
     * @return array ['compatible' => Collection, 'incompatible' => [ ['template_id', 'type', 'algorithm', 'reason'] ]]
     */
    public function compatibility(Device $device, string $employeeId): array
    {
        $compatible = new Collection();
        $incompatible = [];
        foreach ($this->templatesOf($employeeId) as $tpl) {
            $deviceVer = $this->deviceBioVersion($device, (int) $tpl->bio_type);
            if ($deviceVer === null) {
                $reason = 'device has no ' . BiometricTemplate::typeName((int) $tpl->bio_type) . ' support reported';
            } elseif ($this->majorPart($deviceVer) !== (string) $tpl->major_ver) {
                $reason = 'algorithm ' . $tpl->major_ver . ' differs from device ' . $this->majorPart($deviceVer);
            } else {
                $compatible->push($tpl);
                continue;
            }
            $incompatible[] = ['template_id' => $tpl->id, 'type' => BiometricTemplate::typeName((int) $tpl->bio_type),
                               'algorithm' => $tpl->major_ver, 'reason' => $reason];
        }

        return ['compatible' => $compatible, 'incompatible' => $incompatible];
    }

    /**
     * Queue the employees' compatible templates to a device. Their user must be on it already
     * (or queued just before: commands run in order). Only changed templates are sent unless $force.
     *
     * @return array counts
     */
    public function queueTemplates(Device $device, array $employeeIds, bool $force = false, ?string $createdBy = null,
                                   string $source = 'manual'): array
    {
        $counts = ['queued' => 0, 'already_on_device' => 0, 'already_queued' => 0, 'incompatible' => 0, 'no_user' => 0];

        foreach (array_unique(array_map('strval', $employeeIds)) as $employeeId) {
            $user = DeviceUser::where('device_id', $device->id)->where('employee_id', $employeeId)
                ->whereNotIn('sync_status', ['removed', 'removing'])->first();
            if (!$user) {
                $counts['no_user']++;
                continue;
            }
            $c = $this->compatibility($device, $employeeId);
            $counts['incompatible'] += count($c['incompatible']);

            foreach ($c['compatible'] as $tpl) {
                $dt = DeviceTemplate::firstOrNew(['device_id' => $device->id, 'template_id' => $tpl->id]);
                if (!$force && $dt->exists && $dt->template_hash === $tpl->template_hash
                    && in_array($dt->status, ['success', 'on_device'], true) && $dt->pin === $user->pin) {
                    $counts['already_on_device']++;
                    continue;
                }
                if ($dt->exists && $dt->status === 'queued' && $dt->template_hash === $tpl->template_hash && $dt->last_command_id
                    && Command::whereKey($dt->last_command_id)->whereIn('status', [Command::STATUS_QUEUED, Command::STATUS_SENT])->exists()) {
                    $counts['already_queued']++;
                    continue;
                }

                $dt->fill(['pin' => $user->pin, 'template_hash' => $tpl->template_hash, 'status' => 'queued', 'last_error' => null]);
                $dt->save();
                $cmd = $this->commands->queue($device, 'bio_update', $this->buildUpdateCommand($tpl, $user->pin), [
                    'device_user_id' => $user->id,
                    'employee_id'    => $employeeId,
                    'meta'           => ['device_template_id' => $dt->id, 'hash' => $tpl->template_hash,
                                         'type' => BiometricTemplate::typeName((int) $tpl->bio_type)],
                    'source'         => $source,
                    'created_by'     => $createdBy,
                ]);
                $dt->last_command_id = $cmd->id;
                $dt->save();
                $counts['queued']++;
            }
        }

        return $counts;
    }

    /** Command that writes one template to a device under the given PIN. */
    public function buildUpdateCommand(BiometricTemplate $tpl, string $pin): string
    {
        $tmp = (string) $tpl->template;
        $size = strlen($tmp);
        $type = (int) $tpl->bio_type;
        $major = (int) $tpl->major_ver;

        if ($type === BiometricTemplate::TYPE_PHOTO) {
            return "DATA UPDATE USERPIC PIN={$pin}\tSize={$size}\tContent={$tmp}";
        }
        if ($type === BiometricTemplate::TYPE_FINGERPRINT && $major < 12) {
            return "DATA UPDATE FINGERTMP PIN={$pin}\tFID={$tpl->bio_no}\tSize={$size}\tValid=" . ($tpl->duress ? 3 : 1) . "\tTMP={$tmp}";
        }
        if ($type === BiometricTemplate::TYPE_FACE && $major < 12) {
            return "DATA UPDATE FACE PIN={$pin}\tFID={$tpl->bio_index}\tValid=1\tSize={$size}\tTMP={$tmp}";
        }

        return "DATA UPDATE BIODATA Pin={$pin}\tNo={$tpl->bio_no}\tIndex={$tpl->bio_index}\tValid=1\tDuress=" . ($tpl->duress ? 1 : 0)
            . "\tType={$type}\tMajorVer={$tpl->major_ver}\tMinorVer=" . ($tpl->minor_ver !== null ? $tpl->minor_ver : 0)
            . "\tFormat={$tpl->format}\tTmp={$tmp}";
    }

    /** Device answered a template write (or it expired / was cancelled: not on the device). */
    public function afterResult(Command $cmd, string $errorText): void
    {
        $id = is_array($cmd->meta) && isset($cmd->meta['device_template_id']) ? $cmd->meta['device_template_id'] : null;
        $dt = $id ? DeviceTemplate::find($id) : null;
        if (!$dt || (int) $dt->last_command_id !== (int) $cmd->id) {
            return;   // superseded by a newer send
        }
        if ($cmd->status === Command::STATUS_SUCCESS) {
            $dt->fill(['status' => 'success', 'last_error' => null, 'synced_at' => now()]);
        } else {
            $dt->fill(['status' => 'failed', 'last_error' => $errorText]);
        }
        $dt->save();
    }

    // ── Device capabilities ──────────────────────────────────────────────

    /** Algorithm version the device reports for a bio type (null = not supported / unknown). */
    public function deviceBioVersion(Device $device, int $type): ?string
    {
        $kv = Protocol::keyValues((string) $device->options);
        if ($type === BiometricTemplate::TYPE_PHOTO) {
            // user photos have no algorithm: any device that shows user photos takes them
            return Protocol::kv($kv, 'PhotoFunOn') === '1' ? '0' : null;
        }
        if ($type === BiometricTemplate::TYPE_FINGERPRINT && $device->fp_algorithm) {
            return (string) $device->fp_algorithm;
        }
        if ($type === BiometricTemplate::TYPE_FACE && $device->face_algorithm) {
            return (string) $device->face_algorithm;
        }
        $multi = Protocol::kv($kv, 'MultiBioVersion');   // slot n = BioType n, e.g. 0:10.0:7.0:0:0:0:0:0:0:39.1
        if ($multi !== null) {
            $slots = explode(':', $multi);
            if (isset($slots[$type]) && $slots[$type] !== '' && $slots[$type] !== '0') {
                return $slots[$type];
            }
        }
        if ($type === BiometricTemplate::TYPE_PALM && Protocol::kv($kv, 'PvFunOn') === '1') {
            return Protocol::kv($kv, 'PvVersion');
        }

        return null;
    }

    /** Newer firmware: templates go through the unified BIODATA table. */
    public function supportsBioData(Device $device): bool
    {
        $kv = Protocol::keyValues((string) $device->options);

        return Protocol::kv($kv, 'BioDataFun') === '1' || strpos((string) Protocol::kv($kv, 'MultiBioDataSupport'), '1') !== false;
    }

    /** Bio types the device supports (from MultiBioDataSupport, e.g. 0:1:1:0:0:0:0:0:1:1). */
    private function deviceBioTypes(Device $device): array
    {
        $kv = Protocol::keyValues((string) $device->options);
        $types = [];
        foreach (explode(':', (string) Protocol::kv($kv, 'MultiBioDataSupport')) as $type => $on) {
            if ($type > 0 && $on === '1') {
                $types[] = $type;
            }
        }

        return $types ?: [BiometricTemplate::TYPE_FINGERPRINT];
    }

    /** "10.0" → "10", "7" → "7". */
    private function majorPart(string $version): string
    {
        $version = trim($version);

        return $version === '' ? '' : (string) (int) $version;
    }
}
