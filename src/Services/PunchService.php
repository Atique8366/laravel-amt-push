<?php

namespace BiometricPush\AmtPush\Services;

use Illuminate\Database\QueryException;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Protocol\DeviceTime;
use BiometricPush\AmtPush\Protocol\Frame;

/**
 * Stores punches from TimeLog_v2 events and GetFirstGlog / GetNextGlog answers.
 *
 * Duplicates are recognised by (device, log epoch, LogID). Devices restart LogIDs at 1 after a
 * log clear: a known LogID arriving with a different user or time means the log was cleared
 * on the device (or by another server), so a new epoch starts instead of the punch being lost.
 */
class PunchService
{
    /** @var UserService */
    private $users;

    public function __construct(UserService $users)
    {
        $this->users = $users;
    }

    /**
     * @return array{0: Punch|null, 1: bool} [punch, is new]; punch null when the frame has no usable user / time
     */
    public function storeFrame(Device $device, Frame $frame, string $source, bool $moveCursor = true): array
    {
        $userId = $frame->get('UserID');
        $time = DeviceTime::parse($frame->get('Time'));
        if ($userId === '' || !$time) {
            return [null, false];
        }

        $logId = $frame->int('LogID');
        $values = [
            'serial_number'      => $device->serial_number,
            'user_id'            => mb_substr($userId, 0, 32),
            'punched_at'         => $time->format('Y-m-d H:i:s'),
            'utc_offset_minutes' => $frame->int('UtcTimezoneMinutes'),
            'action'             => $this->cut($frame->opt('Action'), 32),
            'attend_stat'        => $this->cut($frame->opt('AttendStat'), 32),
            'ap_stat'            => $this->cut($frame->opt('APStat'), 32),
            'job_code'           => (int) $frame->int('JobCode'),
            'photo'              => $frame->yes('Photo'),
            'log_image'          => config('amt-push.punches.store_log_image') ? $frame->opt('LogImage') : null,
            'latitude'           => $this->cut($frame->opt('Latitude'), 32),
            'longitude'          => $this->cut($frame->opt('Longitude'), 32),
            'source'             => $source,
        ];

        return $this->store($device, $logId !== null && $logId > 0 ? $logId : null, $values, $moveCursor);
    }

    /** @return array{0: Punch, 1: bool} */
    public function store(Device $device, ?int $logId, array $values, bool $moveCursor = true): array
    {
        $device->refresh();

        if ($logId === null) {
            $same = Punch::where('device_id', $device->id)->where('user_id', $values['user_id'])
                ->where('punched_at', $values['punched_at'])->first();
            if ($same) {
                return [$same, false];
            }
        } else {
            $existing = Punch::where('device_id', $device->id)->where('log_epoch', (int) $device->log_epoch)
                ->where('log_id', $logId)->first();
            if ($existing) {
                if ($existing->user_id === $values['user_id'] && $existing->punched_at->format('Y-m-d H:i:s') === $values['punched_at']) {
                    return [$existing, false];
                }
                $this->startNewEpoch($device, 'LogID ' . $logId . ' reused with other data');
            }
        }

        $link = $this->users->linkFor($device, $values['user_id']);
        try {
            $punch = Punch::create($values + [
                'device_id'   => $device->id,
                'log_epoch'   => (int) $device->log_epoch,
                'log_id'      => $logId,
                'employee_id' => $link->employee_id,
            ]);
        } catch (QueryException $e) {
            // the same punch arrived twice at once (push and pull)
            $same = Punch::where('device_id', $device->id)->where('log_epoch', (int) $device->log_epoch)->where('log_id', $logId)->first();
            if ($same) {
                return [$same, false];
            }
            throw $e;
        }

        $update = ['last_punch_at' => now()];
        if ($moveCursor && $logId !== null && ($device->last_log_id === null || $logId > (int) $device->last_log_id)) {
            $update['last_log_id'] = $logId;
        }
        Device::whereKey($device->id)->update($update);

        return [$punch, true];
    }

    /** The device's log was cleared: LogIDs start again. */
    public function startNewEpoch(Device $device, string $reason = 'cleared'): void
    {
        $device->forceFill([
            'log_epoch'   => (int) $device->log_epoch + 1,
            'last_log_id' => null,
            'cleared_at'  => now(),
        ])->save();
        \Illuminate\Support\Facades\Log::info('[amt-push] new log epoch', ['sn' => $device->serial_number, 'reason' => $reason]);
    }

    private function cut(?string $v, int $max): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $max);
    }
}
