<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\AmtPush\Events\CommandFinished;
use BiometricPush\AmtPush\Exceptions\ReadOnlyDeviceException;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Tests\TestCase;

class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amt-push.devices.set_time_on_connect' => false]);
        $this->approvedDevice();
    }

    public function test_one_request_in_flight_at_a_time()
    {
        Event::fake([CommandFinished::class]);
        $t = $this->terminal()->online();
        $d = Amt::device('AMT800-0001')->by(5);
        $a = $d->syncTime();
        $b = $d->enable(false);

        $this->assertSame('SetTime', $t->poll()->get('Request'));
        $this->assertNull($t->poll(), 'nothing else is sent before the answer');
        $this->assertSame('sent', $a->fresh()->status);

        $next = $t->answer('SetTime');
        $this->assertSame('EnableDevice', $next->get('Request'), 'the next one follows the answer right away');
        $this->assertSame('No', $next->get('Enable'));
        $t->answer('EnableDevice');

        $this->assertSame(['succeeded', 'succeeded'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame('5', $a->fresh()->created_by);
        Event::assertDispatchedTimes(CommandFinished::class, 2);
    }

    public function test_commands_wait_for_the_device_to_connect()
    {
        $cmd = Amt::device('AMT800-0001')->syncTime();
        $this->kernel()->tick();
        $this->assertSame('pending', $cmd->fresh()->status);

        $t = $this->terminal();
        $t->register();
        $this->assertSame('SetTime', $t->lastRequest()->get('Request'));
    }

    public function test_an_answer_for_another_request_does_not_settle_the_one_in_flight()
    {
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->syncTime();
        $t->poll();

        $t->answer('GetGlogPosInfo', ['LogCount' => 5, 'Result' => 'OK']);   // late answer of something else

        $this->assertSame('sent', $cmd->fresh()->status);
        $t->answer('SetTime');
        $this->assertSame('succeeded', $cmd->fresh()->status);
    }

    public function test_failure_is_retried_then_final()
    {
        config(['amt-push.commands.max_attempts' => 2, 'amt-push.commands.retry_delay_seconds' => 0]);
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->syncTime();

        $t->poll();
        $again = $t->answer('SetTime', ['Result' => 'Device Not Ready']);
        $this->assertSame('device replied: Device Not Ready', $cmd->fresh()->error);
        $this->assertSame('SetTime', $again->get('Request'), 'sent again (retry delay 0)');

        $t->answer('SetTime', ['Result' => 'Fail']);
        $this->assertSame('failed', $cmd->fresh()->status);
        $this->assertSame(2, $cmd->fresh()->attempts);
    }

    public function test_unanswered_request_times_out()
    {
        config(['amt-push.commands.max_attempts' => 1]);
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->syncTime();
        $t->poll();

        $this->kernel()->sessions->find($t->socket->id())->pendingSince = time() - 60;
        $this->kernel()->tick();

        $this->assertSame('timed_out', $cmd->fresh()->status);
    }

    public function test_connection_closing_mid_request_sends_it_again_later()
    {
        config(['amt-push.commands.retry_delay_seconds' => 0]);
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->syncTime();
        $t->poll();
        $t->disconnect();
        $this->assertSame('pending', $cmd->fresh()->status);

        $t->connect();
        $t->login();
        $this->assertSame('SetTime', $t->lastRequest()->get('Request'));
    }

    public function test_batch_runs_in_order_and_stops_at_the_first_failure()
    {
        config(['amt-push.commands.max_attempts' => 1]);
        $t = $this->terminal()->online();
        $batch = app(\BiometricPush\AmtPush\Services\CommandQueue::class)->batch(Device::first(), [
            ['SetTime', []], ['EnableDevice', ['enable' => true]], ['GetGlogPosInfo', []],
        ]);

        $t->poll();
        $t->answer('SetTime', ['Result' => 'Fail']);

        $this->assertSame(['failed', 'failed', 'failed'], $batch->map(function ($c) {
            return $c->fresh()->status;
        })->all());
        $this->assertStringStartsWith('aborted:', $batch[1]->fresh()->error);
        $this->assertNull($t->poll());
    }

    public function test_build_errors_fail_without_sending()
    {
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->lockControl(42);

        $this->assertNull($t->poll());
        $this->assertSame('failed', $cmd->fresh()->status);
        $this->assertStringStartsWith('build: LockControl', $cmd->fresh()->error);
    }

    public function test_read_only_device()
    {
        $t = $this->terminal()->online();
        $d = Amt::device('AMT800-0001');
        $write = $d->syncTime();
        $d->readOnly();

        $this->assertSame('cancelled', $write->fresh()->status);
        $info = $d->refreshInfo();
        $this->assertCount(4, $info, 'reads are allowed');

        $this->expectException(ReadOnlyDeviceException::class);
        $d->setUser('1', 'Jane');
    }

    public function test_device_information_is_stored()
    {
        $t = $this->terminal()->online();
        Amt::device('AMT800-0001')->refreshInfo();

        $t->poll();
        $t->answer('GetFirmwareVersion', ['Version' => 'AMT800 v3.4.1', 'BuildNumber' => '0x20250101']);
        $t->answer('GetDeviceInfoAll', ['Language' => '1', 'LockReleaseTime' => '5']);
        $t->answer('GetDeviceStatusAll', ['UserCount' => '120', 'FpCount' => '230', 'FaceCount' => '80']);
        $t->answer('GetGlogPosInfo', ['LogCount' => '4521', 'MaxCount' => '500000', 'Result' => 'OK']);

        $d = Device::first();
        $this->assertSame('AMT800 v3.4.1', $d->firmware_version);
        $this->assertSame(120, $d->user_count);
        $this->assertSame([4521, 500000], [$d->log_count, $d->log_capacity]);
        $this->assertSame('5', $d->info['LockReleaseTime']);
        $this->assertSame('230', $d->info['FpCount']);
    }

    public function test_cancel_only_while_pending()
    {
        $t = $this->terminal()->online();
        $d = Amt::device('AMT800-0001');
        $sent = $d->syncTime();
        $t->poll();
        $waiting = $d->enable(true);

        $this->assertFalse($d->cancel($sent->id));
        $this->assertTrue($d->cancel($waiting->id));
        $this->assertSame('cancelled', $waiting->fresh()->status);
    }
}
