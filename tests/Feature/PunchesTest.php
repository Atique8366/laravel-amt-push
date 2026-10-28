<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use BiometricPush\AmtPush\Events\PunchesReceived;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Tests\Fixtures\AttendanceHandler;
use BiometricPush\AmtPush\Tests\TestCase;

class PunchesTest extends TestCase
{
    protected function defineAppTables(): void
    {
        Schema::dropIfExists('attendances');
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->string('employee_id');
            $t->string('day');
            $t->string('check_in');
            $t->string('check_out')->nullable();
            $t->string('method')->nullable();
        });
        AttendanceHandler::$failFor = [];
    }

    public function test_a_punch_is_stored_and_acknowledged_with_its_trans_id()
    {
        Event::fake([PunchesReceived::class]);
        $this->approvedDevice();
        $t = $this->terminal()->online();

        $ack = $t->punch(24, '1001', '2026-10-28-T09:00:05Z', 'FACE', 'Duty On', 'TX-9');

        $this->assertSame(['Response' => 'TimeLog_v2', 'TransID' => 'TX-9', 'UserID' => '1001', 'Result' => 'OK'], $ack->toArray());
        $p = Punch::first();
        $this->assertSame([24, '1001', '2026-10-28 09:00:05', 'FACE', 'Duty On', 300, 'push'],
            [$p->log_id, $p->user_id, $p->punched_at->format('Y-m-d H:i:s'), $p->action, $p->attend_stat, $p->utc_offset_minutes, $p->source]);
        $this->assertSame(24, Device::first()->last_log_id);
        Event::assertDispatched(PunchesReceived::class, function ($e) {
            return $e->realtime && $e->punches->count() === 1;
        });
    }

    public function test_a_resent_punch_is_acknowledged_but_not_stored_twice()
    {
        Event::fake([PunchesReceived::class]);
        $this->approvedDevice();
        $t = $this->terminal()->online();

        $t->punch(24, '1001');
        $this->assertSame('OK', $t->punch(24, '1001')->get('Result'));

        $this->assertSame(1, Punch::count());
        Event::assertDispatchedTimes(PunchesReceived::class, 1);
    }

    public function test_reused_log_id_after_a_clear_on_the_device_is_a_new_punch()
    {
        $this->approvedDevice();
        $t = $this->terminal()->online();
        $t->punch(1, '1001', '2026-10-27-T09:00:00Z');
        $t->punch(2, '1001', '2026-10-27-T18:00:00Z');

        // someone cleared the log on the terminal menu: LogIDs start at 1 again
        $t->punch(1, '2002', '2026-10-28-T08:55:00Z');

        $this->assertSame(3, Punch::count());
        $d = Device::first();
        $this->assertSame(1, $d->log_epoch);
        $this->assertSame(1, $d->last_log_id);
        $this->assertNotNull($d->cleared_at);
    }

    public function test_event_without_login_from_an_approved_serial_is_accepted_unknown_is_not()
    {
        $this->approvedDevice();
        $known = $this->terminal();
        $this->assertSame('OK', $known->punch(5, '7')->get('Result'));
        $this->assertTrue(Device::first()->is_online);

        $stranger = $this->terminal('STRANGER');
        $this->assertNull($stranger->punch(5, '7'));
        $this->assertSame('DeviceNotRegistered', $stranger->find('ErrorReport')->get('Error'));
        $this->assertSame(1, Punch::count());
    }

    public function test_unreadable_punch_is_acknowledged_and_skipped()
    {
        $this->approvedDevice();
        $t = $this->terminal()->online();

        $this->assertSame('OK', $t->punch(9, '7', 'not a time')->get('Result'));
        $this->assertSame(0, Punch::count());
    }

    public function test_punches_become_attendance_through_the_handler()
    {
        config(['amt-push.punches.handler' => AttendanceHandler::class, 'amt-push.employees.mode' => 'pin']);
        $this->approvedDevice();
        $t = $this->terminal()->online();

        $t->punch(1, '42', '2026-10-28-T09:00:00Z', 'FP');
        $t->punch(2, '42', '2026-10-28-T18:00:00Z', 'FACE+CD');
        $t->punch(3, '99', '2026-10-28-T09:30:00Z');

        $row = DB::table('attendances')->where('employee_id', '42')->first();
        $this->assertSame(['09:00:00', '18:00:00', 'finger'], [$row->check_in, $row->check_out, $row->method]);
        $this->assertSame(['check_in', 'check_out', 'check_in'], Punch::orderBy('id')->pluck('process_result')->all());
    }

    public function test_unlinked_users_are_unmatched_until_linked()
    {
        config(['amt-push.punches.handler' => AttendanceHandler::class]);
        $this->approvedDevice();
        $t = $this->terminal()->online();
        $t->punch(1, '1001');
        $this->assertSame('unmatched', Punch::value('process_result'));

        Amt::device('AMT800-0001')->linkUser('1001', 77);

        $this->assertSame('check_in', Punch::value('process_result'));
        $this->assertSame('77', DB::table('attendances')->value('employee_id'));
    }

    public function test_handler_failures_roll_back_and_retry()
    {
        config(['amt-push.punches.handler' => AttendanceHandler::class, 'amt-push.employees.mode' => 'pin', 'amt-push.punches.max_attempts' => 2]);
        AttendanceHandler::$failFor = ['42' => 5];
        $this->approvedDevice();
        $t = $this->terminal()->online();

        $t->punch(1, '42');
        $this->assertSame(0, DB::table('attendances')->count());
        $this->assertStringStartsWith('retry:', Punch::value('process_result'));

        Amt::processPunches();
        $this->assertSame('error: attendance service down', Punch::value('process_result'));
    }

    public function test_attendance_query()
    {
        $this->approvedDevice();
        $t = $this->terminal()->online();
        $t->punch(1, '1', '2026-10-27-T09:00:00Z');
        $t->punch(2, '1', '2026-10-28-T09:00:00Z');
        $t->punch(3, '2', '2026-10-28-T10:00:00Z');

        $d = Amt::device('AMT800-0001');
        $this->assertCount(3, $d->attendance());
        $this->assertCount(2, $d->attendance('2026-10-28 00:00:00'));
        $this->assertCount(1, $d->attendance('2026-10-28 00:00:00', null, '2'));
    }
}
