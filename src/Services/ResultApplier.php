<?php

namespace BiometricPush\AmtPush\Services;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Events\CommandFinished;
use BiometricPush\AmtPush\Events\DeviceUsersUploaded;
use BiometricPush\AmtPush\Events\PunchesReceived;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Protocol\Frame;

/**
 * What a device's answer to one of our requests means: the command's status and its effects
 * (punches from a log pull, users, templates, device info, log clears).
 */
class ResultApplier
{
    /** Header tags every device response carries. */
    private const HEADER_TAGS = ['TerminalType', 'TerminalID', 'DeviceSerialNo', 'Response', 'Result'];

    public function onResponse(Device $device, Command $cmd, Frame $frame): void
    {
        $xml = Encoding::maskPayload($frame->raw);

        switch ($cmd->request) {
            case 'GetFirstGlog':
            case 'GetNextGlog':
                $this->glog($device, $cmd, $frame, $xml);
                return;

            case 'GetFingerData':
            case 'GetFaceData':
            case 'GetPalmData':
            case 'GetUserPhoto':
                // Result=Fail here means "nothing in that slot", not an error
                app(BiometricService::class)->onGetResponse($device, $cmd, $frame);
                $this->succeed($device, $cmd, $xml);
                return;

            case 'GetUserData':
                $this->getUser($device, $cmd, $frame, $xml);
                return;

            case 'GetFirstUserData':
            case 'GetNextUserData':
                $this->userList($device, $cmd, $frame, $xml);
                return;
        }

        if (!$frame->isOk() && !$this->answersWithoutResult($cmd->request, $frame)) {
            $this->fail($device, $cmd, $frame, $xml);
            return;
        }

        switch ($cmd->request) {
            case 'GetGlogPosInfo':
                $device->forceFill(array_filter([
                    'log_count'    => $frame->int('LogCount'),
                    'log_capacity' => $frame->int('MaxCount'),
                ], function ($v) {
                    return $v !== null;
                }))->save();
                break;

            case 'GetTime':
            case 'GetDeviceInfoAll':
            case 'GetDeviceStatusAll':
                $values = array_diff_key($frame->toArray(), array_flip(self::HEADER_TAGS));
                $device->info = array_merge((array) $device->info, $values);
                if (isset($values['UserCount']) && ctype_digit($values['UserCount'])) {
                    $device->user_count = (int) $values['UserCount'];
                }
                $device->save();
                break;

            case 'GetFirmwareVersion':
                $device->forceFill(['firmware_version' => mb_substr($frame->get('Version'), 0, 100)])->save();
                break;

            case 'EmptyTimeLog':
            case 'EmptyAllData':
                app(PunchService::class)->startNewEpoch($device, $cmd->request);
                $device->forceFill(['log_count' => 0])->save();
                $this->cancelPulls($device, 'cancelled: logs cleared by ' . $cmd->request);
                if ($cmd->request === 'EmptyAllData') {
                    $this->allUsersRemoved($device, $cmd->request);
                }
                break;

            case 'EmptyUserEnrollmentData':
                $this->allUsersRemoved($device, $cmd->request);
                break;
        }

        $this->succeed($device, $cmd, $xml);
    }

    /** A command reached a final state (answer, failure, timeout, abort, cancel): apply it and announce it. */
    public function afterFinished(Device $device, Command $cmd, ?Frame $frame): void
    {
        if ($cmd->request === 'SetUserData') {
            $this->afterUserWrite($device, $cmd);
        } elseif (in_array($cmd->request, BiometricService::SET_REQUESTS, true)) {
            app(BiometricService::class)->afterSet($cmd);
        }
        if (in_array($cmd->status, [Command::STATUS_FAILED, Command::STATUS_TIMED_OUT], true)) {
            app(CommandQueue::class)->abortRestOfBatch($cmd);
        }
        event(new CommandFinished($device, $cmd, $frame ? $frame->toArray() : []));
    }

    // ─────────────────────────────────────────────────────────────────────

    private function succeed(Device $device, Command $cmd, string $xml): void
    {
        $cmd->markSucceeded($xml);
        $this->afterFinished($device, $cmd, null);
    }

    private function fail(Device $device, Command $cmd, Frame $frame, string $xml): void
    {
        $reason = trim($frame->get('Result') . ' ' . ($frame->get('Error') ?: $frame->get('Reason')));
        $final = stripos($reason, 'No Certificates') !== false || stripos($reason, 'Invalid Param') !== false;
        $cmd->markFailed('device replied: ' . ($reason !== '' ? $reason : 'no Result'), $final, $xml);
        if ($cmd->isFinished()) {
            $this->afterFinished($device, $cmd, $frame);
        }
    }

    /** Some answers carry data but no <Result>. */
    private function answersWithoutResult(string $request, Frame $frame): bool
    {
        if ($frame->get('Result') !== '') {
            return false;
        }

        return in_array($request, ['GetTime', 'GetDeviceInfoAll', 'GetDeviceStatusAll', 'GetFirmwareVersion', 'GetGlogPosInfo'], true);
    }

    private function glog(Device $device, Command $cmd, Frame $frame, string $xml): void
    {
        $p = (array) $cmd->payload;
        $logId = $frame->int('LogID');

        // Result=Fail or no LogID: no more logs
        if (!$frame->isOk() || $logId === null || $logId <= 0) {
            $device->forceFill(['last_pull_at' => now()])->save();
            $this->succeed($device, $cmd, $xml);
            return;
        }

        list($punch, $isNew) = app(PunchService::class)->storeFrame($device, $frame, 'pull', !empty($p['cursor']));
        if ($punch && $isNew) {
            event(new PunchesReceived($device->fresh(), new Collection([$punch]), false));
        }
        $this->succeed($device, $cmd, $xml);

        $next = ['begin_log_pos' => $logId + 1] + array_intersect_key($p, array_flip(['cursor', 'user_id', 'start_time', 'end_time']));
        app(CommandQueue::class)->queue($device, 'GetNextGlog', $next, [
            'batch_id' => $cmd->batch_id, 'sequence' => (int) $cmd->sequence + 1, 'source' => $cmd->source, 'created_by' => $cmd->created_by,
        ]);
        if ($punch && $isNew) {
            app(PunchProcessor::class)->dispatch($device->fresh());
        }
    }

    private function getUser(Device $device, Command $cmd, Frame $frame, string $xml): void
    {
        $p = (array) $cmd->payload;
        if (!$frame->isOk() && $frame->get('UserID') === '') {
            $row = DeviceUser::where('device_id', $device->id)->where('user_id', (string) $p['user_id'])->first();
            if ($row && $row->isOnDevice() && $row->sync_status !== 'queued') {
                app(UserService::class)->markRemoved($device, $row, 'Not on the device');
            }
            $this->succeed($device, $cmd, $xml);
            return;
        }
        $row = app(UserService::class)->importFrame($device, $frame);
        $this->succeed($device, $cmd, $xml);
        if ($row) {
            event(new DeviceUsersUploaded($device, new Collection([$row])));
            if (!empty($p['then_pull'])) {
                app(BiometricService::class)->pullEnrolled($device, $row, (array) $p['then_pull'], $cmd);
            }
        }
    }

    private function userList(Device $device, Command $cmd, Frame $frame, string $xml): void
    {
        $readId = isset($cmd->payload['read_id']) ? (string) $cmd->payload['read_id'] : null;
        $row = $frame->isOk() ? app(UserService::class)->importFrame($device, $frame, $readId) : null;
        $this->succeed($device, $cmd, $xml);
        if ($row) {
            event(new DeviceUsersUploaded($device, new Collection([$row])));
        }

        if ($row && $frame->yes('More')) {
            app(CommandQueue::class)->queue($device, 'GetNextUserData', ['read_id' => $readId], [
                'source' => $cmd->source, 'created_by' => $cmd->created_by,
            ]);
        } elseif ($readId) {
            app(UserService::class)->markMissingAfterRead($device, $readId);
        }
    }

    private function afterUserWrite(Device $device, Command $cmd): void
    {
        $p = (array) $cmd->payload;
        $row = DeviceUser::where('device_id', $device->id)->where('user_id', (string) $p['user_id'])->first();
        if (!$row || ($row->last_command_id && (int) $row->last_command_id !== (int) $cmd->id)) {
            return;   // superseded by a newer write
        }
        $isDelete = isset($p['type']) && $p['type'] === 'Delete';

        if ($cmd->succeeded()) {
            if ($isDelete) {
                app(UserService::class)->markRemoved($device, $row);
                return;
            }
            $row->fill(array_filter([
                'name'       => isset($p['name']) ? $p['name'] : null,
                'privilege'  => isset($p['privilege']) ? $p['privilege'] : null,
                'department' => isset($p['department']) ? (int) $p['department'] : null,
                'enabled'    => array_key_exists('enabled', $p) && $p['enabled'] !== null ? (bool) $p['enabled'] : null,
            ], function ($v) {
                return $v !== null;
            }) + ['sync_status' => 'synced', 'synced_at' => now(), 'removed_at' => null, 'last_error' => null,
                   'last_command_id' => $cmd->id]);
        } elseif ($cmd->status === Command::STATUS_CANCELLED) {
            $row->fill(['sync_status' => 'pending', 'last_error' => $cmd->error]);
        } else {
            $row->fill(['sync_status' => 'failed', 'last_error' => $cmd->error, 'last_command_id' => $cmd->id]);
        }
        $row->save();
    }

    private function allUsersRemoved(Device $device, string $by): void
    {
        $users = app(UserService::class);
        foreach (DeviceUser::where('device_id', $device->id)->whereNotIn('sync_status', ['removed'])->get() as $row) {
            $users->markRemoved($device, $row, 'Cleared by ' . $by);
        }
    }

    private function cancelPulls(Device $device, string $reason): void
    {
        Command::where('device_id', $device->id)->whereIn('request', ['GetFirstGlog', 'GetNextGlog'])
            ->where('status', Command::STATUS_PENDING)
            ->update(['status' => Command::STATUS_CANCELLED, 'error' => $reason, 'finished_at' => now(), 'updated_at' => now()]);
    }
}
