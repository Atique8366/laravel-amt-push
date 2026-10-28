<?php

namespace BiometricPush\AmtPush\Protocol;

use Illuminate\Support\Carbon;
use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;

/**
 * Turns a queued Command into the XML written to the device. Runs at send time, so SetTime
 * carries the real "now" and templates are read in their latest version.
 *
 * Requests without a dedicated method are sent as-is from payload['tags'] ([Tag => value]),
 * so any request of the M50 WebSocket protocol can be used.
 *
 * @throws \RuntimeException when the payload can't produce a valid message (the command fails, unsent)
 */
class CommandBuilder
{
    public function build(Command $cmd, Device $device): string
    {
        $p = is_array($cmd->payload) ? $cmd->payload : [];
        $method = 'build' . $cmd->request;

        if (method_exists($this, $method)) {
            return $this->{$method}($p, $device);
        }

        $b = MessageBuilder::request($cmd->request);
        foreach (isset($p['tags']) ? (array) $p['tags'] : [] as $tag => $value) {
            $b->add((string) $tag, is_array($value) ? null : $value);
        }

        return $b->toXml();
    }

    // ── Users ────────────────────────────────────────────────────────────

    protected function buildSetUserData(array $p, Device $device): string
    {
        $userId = $this->userId($p);
        $type = isset($p['type']) ? (string) $p['type'] : 'Set';

        $b = MessageBuilder::request('SetUserData')->add('UserID', $userId)->add('Type', $type);
        if ($type === 'Delete') {
            return $b->toXml();
        }

        // Only what was given is sent: anything left out stays as it is on the device.
        if (array_key_exists('name', $p) && $p['name'] !== null) {
            $b->add('Name', Encoding::utf16((string) $p['name']));
        }
        $b->add('Privilege', isset($p['privilege']) ? (string) $p['privilege'] : null);
        $b->add('Depart', isset($p['department']) ? (int) $p['department'] : null);
        if (array_key_exists('enabled', $p) && $p['enabled'] !== null) {
            $b->add('Enabled', (bool) $p['enabled']);
        }
        for ($i = 1; $i <= 5; $i++) {
            $b->add('TimeSet' . $i, isset($p['timesets'][$i - 1]) ? (int) $p['timesets'][$i - 1] : null);
        }
        if (array_key_exists('period', $p) && is_array($p['period'])) {
            $start = $this->date(isset($p['period'][0]) ? $p['period'][0] : null);
            $end = $this->date(isset($p['period'][1]) ? $p['period'][1] : null);
            $b->add('UserPeriod_Used', $start && $end)
              ->add('UserPeriod_Start', $start ? Encoding::packDate($start->year, $start->month, $start->day) : Encoding::packDate(2000, 1, 1))
              ->add('UserPeriod_End', $end ? Encoding::packDate($end->year, $end->month, $end->day) : Encoding::packDate(2000, 1, 1));
        }
        if (array_key_exists('card', $p) && $p['card'] !== null) {
            $b->add('Card', Encoding::int32((int) $p['card']));
        }
        if (array_key_exists('password', $p) && $p['password'] !== null) {
            $b->add('PWD', substr(preg_replace('/\D/', '', (string) $p['password']), 0, 8));
        }
        if (array_key_exists('qr', $p) && $p['qr'] !== null) {
            $b->add('QR', Encoding::int32((int) $p['qr']));
        }
        if (array_key_exists('allow_no_certificate', $p) && $p['allow_no_certificate'] !== null) {
            $b->add('AllowNoCertificate', (bool) $p['allow_no_certificate']);
        }

        return $b->toXml();
    }

    protected function buildGetUserData(array $p, Device $device): string
    {
        return MessageBuilder::request('GetUserData')->add('UserID', $this->userId($p))->toXml();
    }

    // ── Biometrics ───────────────────────────────────────────────────────

    protected function buildSetFingerData(array $p, Device $device): string
    {
        $b = MessageBuilder::request('SetFingerData')->add('UserID', $this->userId($p))
            ->add('Privilege', isset($p['privilege']) ? $p['privilege'] : config('amt-push.users.default_privilege', 'User'));
        if (!empty($p['delete'])) {
            return $b->add('FingerNo', $this->slot($p, 'finger_no', 9))->toXml();   // no FingerData = delete
        }
        $tpl = $this->template($p, Biometric::FINGER);

        return $b->add('FingerNo', $tpl->slot)
            ->add('DuplicationCheck', isset($p['duplication_check']) ? (string) $p['duplication_check'] : '1')
            ->add('Duress', $tpl->duress ? '1' : '0')
            ->add('FingerData', $tpl->template)
            ->toXml();
    }

    protected function buildSetFaceData(array $p, Device $device): string
    {
        $b = MessageBuilder::request('SetFaceData')->add('UserID', $this->userId($p))
            ->add('Privilege', isset($p['privilege']) ? $p['privilege'] : config('amt-push.users.default_privilege', 'User'));
        if (!empty($p['delete'])) {
            return $b->toXml();
        }

        return $b->add('DuplicationCheck', isset($p['duplication_check']) ? (string) $p['duplication_check'] : 'Yes')
            ->add('FaceData', $this->template($p, Biometric::FACE)->template)
            ->toXml();
    }

    protected function buildSetPalmData(array $p, Device $device): string
    {
        $b = MessageBuilder::request('SetPalmData')->add('UserID', $this->userId($p))
            ->add('Privilege', isset($p['privilege']) ? $p['privilege'] : config('amt-push.users.default_privilege', 'User'));
        if (!empty($p['delete'])) {
            return $b->add('PalmNo', $this->slot($p, 'palm_no', 9))->toXml();
        }
        $tpl = $this->template($p, Biometric::PALM);

        return $b->add('PalmNo', $tpl->slot)
            ->add('DuplicationCheck', isset($p['duplication_check']) ? (string) $p['duplication_check'] : '1')
            ->add('PalmData', $tpl->template)
            ->toXml();
    }

    protected function buildSetUserPhoto(array $p, Device $device): string
    {
        $b64 = isset($p['photo']) ? (string) $p['photo'] : $this->template($p, Biometric::PHOTO)->template;
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('SetUserPhoto: photo is not valid base64');
        }
        $max = (int) config('amt-push.biometrics.max_photo_bytes', 32768);
        if (strlen($bytes) > $max) {
            throw new \RuntimeException('SetUserPhoto: photo is ' . strlen($bytes) . " bytes, the device takes at most {$max}");
        }

        return MessageBuilder::request('SetUserPhoto')->add('UserID', $this->userId($p))
            ->add('Privilege', isset($p['privilege']) ? $p['privilege'] : config('amt-push.users.default_privilege', 'User'))
            ->add('PhotoSize', strlen($bytes))
            ->add('PhotoData', $b64)
            ->toXml();
    }

    protected function buildGetFingerData(array $p, Device $device): string
    {
        return MessageBuilder::request('GetFingerData')->add('UserID', $this->userId($p))
            ->add('FingerNo', $this->slot($p, 'finger_no', 9))->toXml();
    }

    protected function buildGetFaceData(array $p, Device $device): string
    {
        return MessageBuilder::request('GetFaceData')->add('UserID', $this->userId($p))->toXml();
    }

    protected function buildGetPalmData(array $p, Device $device): string
    {
        return MessageBuilder::request('GetPalmData')->add('UserID', $this->userId($p))
            ->add('PalmNo', $this->slot($p, 'palm_no', 9))->toXml();
    }

    // ── Logs ─────────────────────────────────────────────────────────────

    protected function buildGetFirstGlog(array $p, Device $device): string
    {
        $b = MessageBuilder::request('GetFirstGlog')->add('BeginLogPos', isset($p['begin_log_pos']) ? (int) $p['begin_log_pos'] : 0);
        if (!empty($p['user_id'])) {
            $b->add('UserID', (string) $p['user_id']);
        }
        foreach (['start_time' => 'StartTime', 'end_time' => 'EndTime'] as $key => $tag) {
            if (!empty($p[$key]) && ($t = $this->date($p[$key]))) {
                $b->add($tag, DeviceTime::format($t));
            }
        }

        return $b->toXml();
    }

    protected function buildGetNextGlog(array $p, Device $device): string
    {
        if (!isset($p['begin_log_pos'])) {
            throw new \RuntimeException('GetNextGlog: begin_log_pos missing');
        }

        return MessageBuilder::request('GetNextGlog')->add('BeginLogPos', (int) $p['begin_log_pos'])->toXml();
    }

    protected function buildDeleteGlogWithPos(array $p, Device $device): string
    {
        return MessageBuilder::request('DeleteGlogWithPos')->add('EndPos', (int) (isset($p['end_pos']) ? $p['end_pos'] : 0))->toXml();
    }

    // ── Device ───────────────────────────────────────────────────────────

    protected function buildSetTime(array $p, Device $device): string
    {
        $now = Carbon::now(isset($p['timezone']) ? $p['timezone'] : $device->clockTimezone());

        return MessageBuilder::request('SetTime')->add('Time', DeviceTime::formatUnpadded($now))->toXml();
    }

    protected function buildEnableDevice(array $p, Device $device): string
    {
        if (!array_key_exists('enable', $p)) {
            throw new \RuntimeException('EnableDevice: "enable" missing');
        }

        return MessageBuilder::request('EnableDevice')->add('Enable', (bool) $p['enable'])->toXml();
    }

    protected function buildSetDepartment(array $p, Device $device): string
    {
        $no = isset($p['dept_no']) ? (int) $p['dept_no'] : -1;
        if ($no < 0 || $no > 99) {
            throw new \RuntimeException('SetDepartment: dept_no must be 0-99');
        }

        return MessageBuilder::request('SetDepartment')->add('DeptNo', $no)
            ->add('Data', Encoding::utf16(isset($p['name']) ? (string) $p['name'] : ''))->toXml();
    }

    protected function buildLockControl(array $p, Device $device): string
    {
        $mode = isset($p['mode']) ? (int) $p['mode'] : 0;
        if ($mode < 1 || $mode > 6) {
            throw new \RuntimeException('LockControl: mode must be 1-6');
        }

        return MessageBuilder::request('LockControl')->add('Mode', $mode)->toXml();
    }

    // ─────────────────────────────────────────────────────────────────────

    private function userId(array $p): string
    {
        $id = isset($p['user_id']) ? trim((string) $p['user_id']) : '';
        if ($id === '') {
            throw new \RuntimeException('user_id missing');
        }

        return $id;
    }

    private function slot(array $p, string $key, int $max): int
    {
        if (!isset($p[$key]) || (int) $p[$key] < 0 || (int) $p[$key] > $max) {
            throw new \RuntimeException("{$key} missing or out of range 0-{$max}");
        }

        return (int) $p[$key];
    }

    private function template(array $p, string $type): Biometric
    {
        $tpl = isset($p['biometric_id']) ? Biometric::find($p['biometric_id']) : null;
        if (!$tpl || $tpl->type !== $type || (string) $tpl->template === '') {
            throw new \RuntimeException("{$type} template " . (isset($p['biometric_id']) ? '#' . $p['biometric_id'] : '') . ' not found');
        }

        return $tpl;
    }

    /** @param mixed $value */
    private function date($value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        try {
            return $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
