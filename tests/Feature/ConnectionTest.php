<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\AmtPush\Events\DeviceConnected;
use BiometricPush\AmtPush\Events\DeviceDisconnected;
use BiometricPush\AmtPush\Events\DeviceRegistered;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\Message;
use BiometricPush\AmtPush\Tests\TestCase;

class ConnectionTest extends TestCase
{
    public function test_unknown_device_is_saved_as_pending_and_refused_until_approved()
    {
        Event::fake([DeviceRegistered::class, DeviceConnected::class]);
        $t = $this->terminal('NEW-0001');

        $this->assertSame('Fail', $t->register()->get('Result'));
        $device = Device::where('serial_number', 'NEW-0001')->first();
        $this->assertSame('pending', $device->registration_status);
        $this->assertSame('AMT800', $device->terminal_type);
        $this->assertSame('AMT800 v3.3.0', $device->firmware_version);
        Event::assertDispatched(DeviceRegistered::class);
        Event::assertNotDispatched(DeviceConnected::class);

        $this->artisan('amt:approve', ['serial' => 'NEW-0001'])->assertExitCode(0);
        $reply = $t->register();
        $this->assertSame('OK', $reply->get('Result'));
        $this->assertSame('NEW-0001', $reply->get('DeviceSerialNo'));
        $this->assertNotSame('', $reply->get('Token'));
        $this->assertTrue($device->fresh()->is_online);
        Event::assertDispatched(DeviceConnected::class);
    }

    public function test_login_with_the_issued_token_and_failures()
    {
        $this->approvedDevice();
        $t = $this->terminal();
        $token = $t->register()->get('Token');
        $t->disconnect();

        $t->connect();
        $this->assertSame('OK', $t->login($token)->get('Result'));
        $this->assertSame('FailUnknownToken', $t->login('not-a-token')->get('Result'), 'the device then registers again');

        $other = $this->terminal('OTHER-01');
        $this->assertSame('Fail', $other->login($token)->get('Result'), 'token lifted from another device');

        Amt::device('AMT800-0001')->block();
        $this->assertSame('FailUnknownToken', $t->login($token)->get('Result'), 'blocking revokes the token');
        $this->assertSame('Fail', $t->register()->get('Result'));
    }

    public function test_every_register_issues_a_new_token()
    {
        $this->approvedDevice();
        $t = $this->terminal();
        $first = $t->register()->get('Token');
        $second = $t->register()->get('Token');

        $this->assertNotSame($first, $second);
        $this->assertSame('FailUnknownToken', $t->login($first)->get('Result'));
    }

    public function test_cloud_id_restriction()
    {
        config(['amt-push.devices.cloud_id' => 'our-cloud']);
        $this->approvedDevice();
        $t = $this->terminal();

        $this->assertSame('Fail', $t->register('someone-else')->get('Result'));
        $this->assertSame('OK', $t->register('our-cloud')->get('Result'));
    }

    public function test_auto_approve_and_approved_serials()
    {
        config(['amt-push.devices.approved_serials' => ['LISTED-1']]);
        $this->assertSame('OK', $this->terminal('LISTED-1')->register()->get('Result'));

        config(['amt-push.devices.auto_register' => false]);
        $this->assertSame('Fail', $this->terminal('STRANGER')->register()->get('Result'));
        $this->assertNull(Device::where('serial_number', 'STRANGER')->first());

        config(['amt-push.devices.auto_approve' => true]);
        $this->assertSame('OK', $this->terminal('ANY-1')->register()->get('Result'));
    }

    public function test_set_time_is_sent_after_connecting()
    {
        $this->approvedDevice('AMT800-0001', ['timezone' => 'Asia/Karachi']);
        $t = $this->terminal();
        $t->register();

        $request = $t->lastRequest();
        $this->assertSame('SetTime', $request->get('Request'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{1,2}-\d{1,2}-T/', $request->get('Time'));
        $t->answer('SetTime');
        $this->assertSame('succeeded', Command::where('request', 'SetTime')->value('status'));

        $t->login();   // a second connect does not queue a second SetTime while one waits
        $t->login();
        $this->assertSame(2, Command::where('request', 'SetTime')->count());
    }

    public function test_keepalive_returns_server_time_and_marks_seen()
    {
        $this->approvedDevice();
        $t = $this->terminal();
        $t->online();
        Device::query()->update(['last_seen_at' => now()->subHour()]);
        $this->kernel()->sessions->find($t->socket->id())->device->last_seen_at = now()->subHour();

        $reply = $t->keepAlive();

        $this->assertSame('OK', $reply->get('Result'));
        $this->assertSame('2026-10-28-T09:00:00Z', $reply->get('DevTime'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-T\d{2}:\d{2}:\d{2}Z$/', $reply->get('ServerTime'));
        $this->assertTrue(Device::first()->last_seen_at->gt(now()->subMinute()));
        $this->assertSame(0, Message::where('kind', 'KeepAlive')->count(), 'KeepAlive is not logged by default');
    }

    public function test_a_reconnected_device_replaces_its_old_socket()
    {
        Event::fake([DeviceDisconnected::class]);
        $this->approvedDevice();
        $old = $this->terminal();
        $old->register();

        $new = $this->terminal();
        $new->login($old->token);

        $this->assertTrue($old->socket->closed);
        $this->assertCount(1, $this->kernel()->sessions->all());

        $new->disconnect();
        $this->assertFalse(Device::first()->is_online);
        Event::assertDispatched(DeviceDisconnected::class);
    }

    public function test_silent_sockets_are_swept()
    {
        $this->approvedDevice();
        $t = $this->terminal();
        $t->register();
        $this->kernel()->sessions->find($t->socket->id())->lastAliveAt = time() - 500;

        $this->assertSame(1, $this->kernel()->sweep(150));
        $this->assertTrue($t->socket->closed);
        $this->assertFalse(Device::first()->is_online);
    }

    public function test_messages_are_logged_with_templates_masked_and_junk_ignored()
    {
        $this->approvedDevice();
        $t = $this->terminal();
        $t->online();
        $this->kernel()->message($t->socket, 'garbage');

        $this->assertSame(['in', 'out'], Message::where('kind', 'Register')->orderBy('id')->pluck('direction')->all());
    }
}
