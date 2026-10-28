<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Tests\TestCase;

class LogsAndClearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amt-push.devices.set_time_on_connect' => false]);
        $this->approvedDevice();
    }

    /** Answer a GetFirstGlog / GetNextGlog chain from a list of [LogID, UserID, time]. */
    private function serveLogs($t, array $logs, $req = null): void
    {
        $req = $req ?: ($t->lastRequest() ?: $t->poll());
        while ($req && in_array($req->get('Request'), ['GetGlogPosInfo', 'GetFirstGlog', 'GetNextGlog'], true)) {
            $name = $req->get('Request');
            if ($name === 'GetGlogPosInfo') {
                $req = $t->answer($name, ['LogCount' => count($logs), 'MaxCount' => 500000, 'Result' => 'OK']);
                continue;
            }
            $from = (int) $req->get('BeginLogPos');
            $next = null;
            foreach ($logs as $log) {
                if ($log[0] >= $from && ($req->get('UserID') === '' || $req->get('UserID') === $log[1])) {
                    $next = $log;
                    break;
                }
            }
            $req = $next
                ? $t->answer($name, ['LogID' => $next[0], 'Time' => $next[2], 'UserID' => $next[1], 'Action' => 'FP',
                    'AttendStat' => 'Duty On', 'APStat' => 'None', 'JobCode' => 0, 'Photo' => 'No', 'Result' => 'OK'])
                : $t->answer($name, ['Result' => 'Fail']);
        }
    }

    public function test_full_pull_walks_the_log_until_fail()
    {
        $t = $this->terminal()->online();
        $batch = Amt::device('AMT800-0001')->pullAttendance(true);
        $this->assertNotNull($batch);
        $this->assertNull(Amt::device('AMT800-0001')->pullAttendance(), 'one pull at a time');

        $this->serveLogs($t, [[1, '7', '2026-10-27-T09:00:00Z'], [2, '7', '2026-10-27-T18:00:00Z'], [3, '8', '2026-10-28-T08:00:00Z']]);

        $this->assertSame([1, 2, 3], Punch::orderBy('log_id')->pluck('log_id')->all());
        $this->assertSame(['pull'], Punch::distinct()->pluck('source')->all());
        $d = Device::first();
        $this->assertSame(3, $d->last_log_id);
        $this->assertSame(3, $d->log_count);
        $this->assertNotNull($d->last_pull_at);
        $this->assertSame(0, Command::whereIn('status', ['pending', 'sent'])->count());
    }

    public function test_incremental_pull_starts_after_the_last_log_id_and_skips_duplicates()
    {
        $t = $this->terminal()->online();
        $t->punch(1, '7', '2026-10-27-T09:00:00Z');   // live push

        Amt::device('AMT800-0001')->pullAttendance();
        $t->poll();
        $req = $t->answer('GetGlogPosInfo', ['LogCount' => 2, 'Result' => 'OK']);
        $this->assertSame('2', $req->get('BeginLogPos'), 'continues after LogID 1');
        $this->serveLogs($t, [[1, '7', '2026-10-27-T09:00:00Z'], [2, '7', '2026-10-27-T18:00:00Z']], $req);

        $this->assertSame(2, Punch::count());
    }

    public function test_reconnect_catches_up_when_a_log_id_is_known()
    {
        $t = $this->terminal()->online();
        $this->assertNotContains('GetFirstGlog', $t->requests(), 'no full-history pull on first contact');

        $t->punch(5, '7');
        $t->disconnect();
        $t->connect();
        $t->login();

        $this->assertSame('GetGlogPosInfo', $t->lastRequest()->get('Request'));
        $this->assertSame(6, Command::where('request', 'GetFirstGlog')->first()->payload['begin_log_pos']);
    }

    public function test_user_filtered_pull_does_not_move_the_log_position()
    {
        $t = $this->terminal()->online();
        $t->punch(10, '7');
        Amt::device('AMT800-0001')->pullAttendanceFor('8', '2026-10-01', '2026-10-31');

        $req = $t->poll();
        $this->assertSame(['8', '2026-10-01-T00:00:00Z'], [$req->get('UserID'), $req->get('StartTime')]);
        $this->serveLogs($t, [[40, '8', '2026-10-20-T09:00:00Z']], $req);

        $this->assertSame(2, Punch::count());
        $this->assertSame(10, Device::first()->last_log_id);
    }

    public function test_clear_waits_for_all_punches_then_starts_a_new_epoch()
    {
        $t = $this->terminal()->online();
        $t->punch(1, '7', '2026-10-27-T09:00:00Z');

        $clear = Amt::device('AMT800-0001')->clearAttendance();
        // the device holds 2 logs but only 1 is stored: the pull runs, the clear waits
        $t->poll();
        $req = $t->answer('GetGlogPosInfo', ['LogCount' => 2, 'Result' => 'OK']);
        $this->assertSame('GetFirstGlog', $req->get('Request'));
        $req = $t->answer('GetFirstGlog', ['LogID' => 2, 'Time' => '2026-10-27-T18:00:00Z', 'UserID' => '7', 'Result' => 'OK']);
        $this->assertSame('GetNextGlog', $req->get('Request'));
        $req = $t->answer('GetNextGlog', ['Result' => 'Fail']);
        $this->assertSame('EmptyTimeLog', $req->get('Request'), 'sent once both punches are stored');
        $t->answer('EmptyTimeLog');

        $this->assertSame('succeeded', $clear->fresh()->status);
        $d = Device::first();
        $this->assertSame([1, null, 0], [$d->log_epoch, $d->last_log_id, $d->log_count]);

        $t->punch(1, '9', '2026-10-28-T08:00:00Z');   // LogID 1 again after the clear
        $this->assertSame(3, Punch::count());
    }

    public function test_clear_is_held_while_punches_are_missing()
    {
        $t = $this->terminal()->online();
        $clear = Amt::device('AMT800-0001')->clearAttendance();
        Command::where('request', 'GetFirstGlog')->update(['status' => 'cancelled']);   // pretend the pull can't run

        $t->poll();
        $t->answer('GetGlogPosInfo', ['LogCount' => 5, 'Result' => 'OK']);
        $this->kernel()->tick();

        $this->assertSame('pending', $clear->fresh()->status);
        $this->assertSame('Waiting: 5 punch(es) on the device are not stored yet', $clear->fresh()->error);
        $this->assertNotContains('EmptyTimeLog', $t->requests());
    }

    public function test_clear_all_data_removes_users_too()
    {
        config(['amt-push.commands.wait_for_uploads' => false]);
        $t = $this->terminal()->online();
        $t->punch(1, '7');
        $this->assertSame(1, DeviceUser::count());

        Amt::device('AMT800-0001')->clearAllData();
        $this->serveLogs($t, [[1, '7', '2026-10-28-T09:00:05Z']]);
        $t->answer('EmptyAllData');

        $this->assertSame('removed', DeviceUser::value('sync_status'));
        $this->assertSame(1, Device::first()->log_epoch);
    }
}
