<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\Adms\Events\PunchesReceived;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Models\RequestLog;
use BiometricPush\Adms\Tests\TestCase;

class AttendanceUploadTest extends TestCase
{
    public function test_punches_are_stored_and_the_stamp_moves()
    {
        $this->approvedDevice();

        $response = $this->sim()->upload('ATTLOG', [
            "1001\t2026-10-27 09:00:01\t0\t1\t0\t0\t0",
            "1002\t2026-10-27 09:00:05\t255\t15\t0\t0\t0",
        ], '12345');

        $response->assertStatus(200);
        $this->assertSame("OK\n", $response->getContent());
        $this->assertSame(2, Punch::count());
        $this->assertSame('15', Punch::where('pin', '1002')->value('verify_mode'));
        $d = Device::first();
        $this->assertSame('12345', $d->attlog_stamp);
        $this->assertNotNull($d->last_attlog_at);
    }

    public function test_resent_punches_are_ignored_and_only_new_ones_are_in_the_event()
    {
        $this->approvedDevice();
        Event::fake([PunchesReceived::class]);
        $sim = $this->sim();

        $sim->upload('ATTLOG', ["1001\t2026-10-27 09:00:01\t0\t1\t0\t0\t0"]);
        $sim->upload('ATTLOG', ["1001\t2026-10-27 09:00:01\t0\t1\t0\t0\t0", "1001\t2026-10-27 18:00:00\t1\t1\t0\t0\t0"]);

        $this->assertSame(2, Punch::count());
        $events = Event::dispatched(PunchesReceived::class);
        $this->assertCount(2, $events);
        $this->assertSame(['2026-10-27 18:00:00'], $events[1][0]->punches->map(function ($p) {
            return $p->punched_at->format('Y-m-d H:i:s');
        })->all());
    }

    public function test_bad_lines_are_reported_and_good_ones_kept()
    {
        $this->approvedDevice();

        $this->sim()->upload('ATTLOG', ["1001\t2026-10-27 09:00:01\t0\t1", "garbage line", "\t2026-10-27"]);

        $this->assertSame(1, Punch::count());
        $this->assertStringContainsString('2 unreadable line(s)', RequestLog::where('table_name', 'ATTLOG')->value('error'));
    }

    public function test_mask_and_temperature_kept_only_when_the_device_has_them()
    {
        $this->approvedDevice();
        $sim = $this->sim();

        $sim->upload('ATTLOG', ["1\t2026-10-27 09:00:00\t0\t15\t0\t0\t0\t1\t36.6"]);
        $this->assertNull(Punch::value('temperature'));

        Device::query()->update(['mask_detection' => true, 'temperature_detection' => true]);
        $sim->upload('ATTLOG', ["2\t2026-10-27 09:00:00\t0\t15\t0\t0\t0\t1\t36.6"]);
        $p = Punch::where('pin', '2')->first();
        $this->assertSame('1', $p->mask_flag);
        $this->assertSame('36.6', $p->temperature);
    }

    public function test_linked_pins_get_the_employee_on_the_punch()
    {
        $device = $this->approvedDevice();
        Adms::device($device)->linkUser('1001', 42);

        $this->sim()->punch('1001', '2026-10-27 09:00:00');
        $this->sim()->punch('2002', '2026-10-27 09:00:00');

        $this->assertSame('42', Punch::where('pin', '1001')->value('employee_id'));
        $this->assertNull(Punch::where('pin', '2002')->value('employee_id'));
    }

    public function test_unapproved_device_punches_are_not_stored()
    {
        Adms::register('PEND1', [], false);

        $this->sim('PEND1')->punch('1', '2026-10-27 09:00:00')->assertStatus(401);
        $this->assertSame(0, Punch::count());
    }

    public function test_attendance_query_through_the_facade()
    {
        $this->approvedDevice();
        $sim = $this->sim();
        $sim->punch('1', '2026-10-26 09:00:00');
        $sim->punch('1', '2026-10-27 09:00:00');
        $sim->punch('2', '2026-10-27 10:00:00');

        $device = Adms::device('SN0001');
        $this->assertCount(3, $device->attendance());
        $this->assertCount(2, $device->attendance('2026-10-27 00:00:00'));
        $this->assertCount(1, $device->attendance('2026-10-27 00:00:00', null, '2'));
    }

    public function test_recollect_resets_the_stamp_and_queues_check()
    {
        $this->approvedDevice('SN0001', ['attlog_stamp' => '999']);

        $cmd = Adms::device('SN0001')->recollectAttendance();

        $this->assertSame('CHECK', $cmd->command);
        $this->assertSame('0', Device::first()->attlog_stamp);
        $this->assertStringContainsString("ATTLOGStamp=0\n", $this->sim()->handshake()->getContent());
    }
}
