<?php

namespace BiometricPush\Adms\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use BiometricPush\Adms\Events\CommandFinished;
use BiometricPush\Adms\Exceptions\ReadOnlyDeviceException;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Tests\TestCase;

class CommandQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_is_delivered_on_poll_and_result_recorded()
    {
        $this->approvedDevice();
        Event::fake([CommandFinished::class]);
        $cmd = Adms::device('SN0001')->by(7)->restart();
        $sim = $this->sim();

        $this->assertSame("C:{$cmd->id}:REBOOT\n", $sim->poll()->getContent());
        $this->assertSame('sent', $cmd->fresh()->status);
        $this->assertSame(1, (int) $cmd->fresh()->attempts);
        $this->assertSame("OK\n", $sim->poll()->getContent(), 'a sent command is not delivered twice');

        $sim->answer([$cmd->id => 0])->assertSeeText('OK');

        $fresh = $cmd->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertSame(0, (int) $fresh->return_code);
        $this->assertSame('7', $fresh->created_by);
        Event::assertDispatched(CommandFinished::class, function ($e) use ($cmd) {
            return $e->command->id === $cmd->id && $e->command->succeeded();
        });
    }

    public function test_negative_return_code_fails_the_command()
    {
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->command('SET OPTION Delay=30');
        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$cmd->id => -1002]);

        $this->assertSame('failed', $cmd->fresh()->status);
        $this->assertSame('Not supported by this device', Command::returnMeaning($cmd->fresh()->return_code));
    }

    public function test_info_result_data_lines_update_the_device()
    {
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->refreshInfo();
        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$cmd->id => [0, '~DeviceName=SpeedFace-V5L', 'UserCount=12', 'TransactionCount=300', 'FaceVersion=7']]);

        $d = Device::first();
        $this->assertSame('SpeedFace-V5L', $d->model);
        $this->assertSame(12, $d->user_count);
        $this->assertSame(300, $d->attlog_count);
    }

    public function test_results_for_another_device_are_ignored()
    {
        $this->approvedDevice('A1');
        $this->approvedDevice('B1');
        $cmd = Adms::device('A1')->restart();

        $this->sim('B1')->answer([$cmd->id => 0]);

        $this->assertSame('queued', $cmd->fresh()->status);
    }

    public function test_unanswered_retriable_commands_are_resent_then_expire()
    {
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->refreshUsers();
        $sim = $this->sim();

        Carbon::setTestNow('2026-10-27 10:00:00');
        for ($i = 1; $i <= 3; $i++) {
            $this->assertArrayHasKey($cmd->id, $sim->poll() ? $sim->received : []);
            Carbon::setTestNow(Carbon::now()->addSeconds(301));
        }
        $sim->poll();

        $this->assertSame('expired', $cmd->fresh()->status);
        $this->assertSame([], $sim->received);
    }

    public function test_reboot_is_never_resent()
    {
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->restart();
        $sim = $this->sim();

        Carbon::setTestNow('2026-10-27 10:00:00');
        $sim->poll();
        Carbon::setTestNow('2026-10-27 10:06:00');
        $sim->poll();

        $this->assertSame('expired', $cmd->fresh()->status);
        $this->assertSame([], $sim->received);
    }

    public function test_read_only_device_refuses_writes_and_cancels_waiting_ones()
    {
        $this->approvedDevice();
        $device = Adms::device('SN0001');
        $reboot = $device->restart();
        $device->readOnly();

        $this->assertSame('cancelled', $reboot->fresh()->status);
        $this->assertSame('queued', $device->refreshInfo()->status, 'reads are still allowed');

        $this->expectException(ReadOnlyDeviceException::class);
        $device->setUser('1', 'Jane');
    }

    public function test_user_delete_waits_until_every_punch_is_uploaded()
    {
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->removeUser('1001');
        $sim = $this->sim();

        $sim->poll();
        $this->assertSame([], $sim->received, 'device never reported its punch count');
        $this->assertStringContainsString('not reported', $cmd->fresh()->response);

        $sim->poll('Ver 8,1,0,2,1.2.3.4,10,7,0,0,0');   // device holds 2 punches, 0 stored
        $this->assertSame([], $sim->received);
        $this->assertStringContainsString('2 punch(es)', $cmd->fresh()->response);

        $sim->punch('5', '2026-10-27 09:00:00');
        $sim->punch('6', '2026-10-27 09:00:00');
        $sim->poll();
        $this->assertSame([$cmd->id => 'DATA DELETE USERINFO PIN=1001'], $sim->received);
    }

    public function test_the_upload_gate_can_be_switched_off()
    {
        config(['adms.commands.wait_for_uploads' => false]);
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->clearAttendance();

        $sim = $this->sim();
        $sim->poll();
        $this->assertSame([$cmd->id => 'CLEAR LOG'], $sim->received);
    }

    public function test_poll_size_limits()
    {
        config(['adms.commands.max_per_poll' => 2]);
        $this->approvedDevice();
        $device = Adms::device('SN0001');
        foreach (range(1, 5) as $i) {
            $device->command('SET OPTION Delay=' . (10 + $i));
        }
        $sim = $this->sim();

        $sim->poll();
        $this->assertCount(2, $sim->received);
        $sim->poll();
        $this->assertCount(2, $sim->received);
    }

    public function test_cancel_only_while_queued()
    {
        $this->approvedDevice();
        $device = Adms::device('SN0001');
        $a = $device->restart();
        $b = $device->refreshInfo();
        $this->sim()->poll();
        $c = $device->refreshUsers();

        $this->assertFalse($device->cancel($a->id), 'already sent');
        $this->assertTrue($device->cancel($c->id));
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertSame('sent', $b->fresh()->status);
    }
}
