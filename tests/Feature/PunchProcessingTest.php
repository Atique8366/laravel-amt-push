<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Jobs\ProcessPunches;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Tests\Fixtures\AttendanceHandler;
use BiometricPush\Adms\Tests\Fixtures\RecordingHandler;
use BiometricPush\Adms\Tests\TestCase;

class PunchProcessingTest extends TestCase
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
        config(['adms.punches.handler' => AttendanceHandler::class]);
    }

    public function test_sync_mode_turns_punches_into_attendance_right_away()
    {
        $this->approvedDevice();
        Adms::device('SN0001')->linkUser('1001', 42);
        $sim = $this->sim();

        $sim->punch('1001', '2026-10-27 09:00:00', '15');
        $sim->punch('1001', '2026-10-27 18:05:00', '1');

        $row = DB::table('attendances')->first();
        $this->assertSame('42', (string) $row->employee_id);
        $this->assertSame('09:00:00', $row->check_in);
        $this->assertSame('18:05:00', $row->check_out);
        $this->assertSame('face', $row->method);
        $this->assertSame(['check_in', 'check_out'], Punch::orderBy('id')->pluck('process_result')->all());
    }

    public function test_unlinked_pins_are_unmatched_until_linked_then_reprocessed()
    {
        $this->approvedDevice();
        $this->sim()->punch('2002', '2026-10-27 09:00:00');
        $this->assertSame('unmatched', Punch::value('process_result'));
        $this->assertSame(0, DB::table('attendances')->count());

        Adms::device('SN0001')->linkUser('2002', 8);

        $this->assertSame('check_in', Punch::value('process_result'));
        $this->assertSame('8', (string) DB::table('attendances')->value('employee_id'));
    }

    public function test_handler_receives_unlinked_punches_when_employee_is_not_required()
    {
        config(['adms.punches.require_employee' => false, 'adms.punches.handler' => RecordingHandler::class]);
        RecordingHandler::$seen = [];
        $this->approvedDevice();

        $this->sim()->punch('3', '2026-10-27 09:00:00');

        $this->assertSame(['3'], RecordingHandler::$seen);
        $this->assertSame('seen', Punch::value('process_result'));
    }

    public function test_failures_roll_back_and_retry_then_give_up()
    {
        config(['adms.punches.max_attempts' => 2]);
        $this->approvedDevice();
        Adms::device('SN0001')->linkUser('1', 1);
        AttendanceHandler::$failFor = ['1' => 5];

        $this->sim()->punch('1', '2026-10-27 09:00:00');
        $p = Punch::first();
        $this->assertNull($p->processed_at);
        $this->assertSame(1, (int) $p->process_attempts);
        $this->assertStringStartsWith('retry: attendance service down', $p->process_result);
        $this->assertSame(0, DB::table('attendances')->count(), 'the failed punch\'s writes were rolled back');

        $this->sim()->poll();   // leftovers are handled on the next poll
        $p->refresh();
        $this->assertNotNull($p->processed_at);
        $this->assertSame('error: attendance service down', $p->process_result);
    }

    public function test_punches_before_punches_from_are_not_handled()
    {
        $this->approvedDevice('SN0001', ['punches_from' => '2026-10-27']);
        Adms::device('SN0001')->linkUser('1', 1);

        $this->sim()->punch('1', '2026-10-26 23:59:00');
        $this->sim()->punch('1', '2026-10-27 00:00:00');

        $this->assertSame(['before_punches_from', 'check_in'], Punch::orderBy('punched_at')->pluck('process_result')->all());
    }

    public function test_processing_switched_off_per_device_or_manual_mode()
    {
        $this->approvedDevice('OFF1', ['process_punches' => false]);
        $this->sim('OFF1')->punch('1', '2026-10-27 09:00:00');
        $this->assertNull(Punch::value('process_result'));

        config(['adms.punches.process' => 'manual']);
        $this->approvedDevice('MAN1');
        Adms::device('MAN1')->linkUser('1', 1);
        $this->sim('MAN1')->punch('1', '2026-10-27 09:00:00');
        $this->assertSame(0, DB::table('attendances')->count());

        $result = Adms::processPunches('MAN1');
        $this->assertSame(1, $result['MAN1']['processed']);
        $this->assertSame(1, DB::table('attendances')->count());
    }

    public function test_queue_mode_dispatches_a_job()
    {
        config(['adms.punches.process' => 'queue', 'adms.punches.queue' => 'attendance']);
        Bus::fake();
        $this->approvedDevice();

        $this->sim()->punch('1', '2026-10-27 09:00:00');

        Bus::assertDispatched(ProcessPunches::class, function ($job) {
            return $job->queue === 'attendance';
        });
    }

    public function test_queue_job_processes_the_device()
    {
        $device = $this->approvedDevice();
        Adms::device($device)->linkUser('1', 1);
        config(['adms.punches.process' => 'manual']);
        $this->sim()->punch('1', '2026-10-27 09:00:00');

        (new ProcessPunches($device->id))->handle(app(\BiometricPush\Adms\Services\PunchProcessor::class));

        $this->assertSame('check_in', Punch::value('process_result'));
    }

    public function test_reprocess_by_ids()
    {
        $this->approvedDevice();
        Adms::device('SN0001')->linkUser('1', 1);
        $this->sim()->punch('1', '2026-10-27 09:00:00');
        DB::table('attendances')->delete();

        $client = Adms::device('SN0001');
        $this->assertSame(1, $client->reprocessPunches([Punch::value('id')]));
        $client->processPunches();

        $this->assertSame(1, DB::table('attendances')->count());
    }
}
