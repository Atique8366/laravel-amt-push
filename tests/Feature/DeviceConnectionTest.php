<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\Adms\Events\DeviceRegistered;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\RequestLog;
use BiometricPush\Adms\Tests\TestCase;

class DeviceConnectionTest extends TestCase
{
    public function test_unknown_device_is_registered_as_pending_and_refused()
    {
        Event::fake([DeviceRegistered::class]);

        $response = $this->sim('NEW001')->handshake();

        $response->assertStatus(401);
        $this->assertSame("Device Unauthorized\n", $response->getContent());
        $device = Device::where('serial_number', 'NEW001')->first();
        $this->assertSame('pending', $device->registration_status);
        $this->assertNotNull($device->last_seen_at);
        Event::assertDispatched(DeviceRegistered::class, function ($e) {
            return $e->device->serial_number === 'NEW001';
        });
    }

    public function test_approved_device_gets_the_handshake_options()
    {
        $this->approvedDevice('SN0001', ['timezone_offset' => '+05:00']);

        $response = $this->sim('SN0001')->handshake();

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("GET OPTION FROM: SN0001\n", $response->getContent());
        $this->assertStringContainsString("TimeZone=300\n", $response->getContent());
        $device = Device::where('serial_number', 'SN0001')->first();
        $this->assertSame('2.4.1', $device->push_version);
        $this->assertSame('69', $device->language);
    }

    public function test_auto_approve_and_approved_serials()
    {
        config(['adms.devices.approved_serials' => ['LISTED1']]);
        $this->sim('LISTED1')->handshake()->assertStatus(200);
        $this->sim('OTHER1')->handshake()->assertStatus(401);

        config(['adms.devices.auto_approve' => true]);
        $this->sim('ANY1')->handshake()->assertStatus(200);
        $this->assertSame('approved', Device::where('serial_number', 'ANY1')->value('registration_status'));
    }

    public function test_auto_register_off_refuses_unknown_serials_without_saving_them()
    {
        config(['adms.devices.auto_register' => false]);

        $this->sim('STRANGER')->handshake()->assertStatus(401);
        $this->assertSame(0, Device::count());
    }

    public function test_blocked_and_push_disabled_devices_are_refused()
    {
        Adms::register('BLK1')->block();
        $this->sim('BLK1')->handshake()->assertStatus(401);

        $this->approvedDevice('OFF1', ['push_enabled' => false]);
        $this->sim('OFF1')->handshake()->assertStatus(401);
    }

    public function test_missing_serial_number()
    {
        $this->call('GET', '/iclock/cdata?options=all')->assertStatus(400);
        $this->call('GET', '/iclock/ping')->assertStatus(200);
    }

    public function test_options_upload_and_info_poll_update_the_device()
    {
        $this->approvedDevice();
        $sim = $this->sim();

        $sim->uploadOptions()->assertStatus(200);
        $sim->poll('Ver 8.0.4.3,149,47,757,192.168.1.201,10,7,12,3,111')->assertStatus(200);

        $d = Device::first();
        $this->assertSame('MB20', $d->model);
        $this->assertSame('10', $d->fp_algorithm);
        $this->assertSame('7', $d->face_algorithm);
        $this->assertSame(149, $d->user_count);
        $this->assertSame(757, $d->attlog_count);
        $this->assertStringContainsString('PhotoFunOn=1', $d->options);
    }

    public function test_aspx_alias_and_unknown_iclock_paths_are_answered_and_logged()
    {
        $this->approvedDevice();

        $this->call('GET', '/iclock/cdata.aspx?SN=SN0001&options=all')->assertStatus(200);
        $this->call('POST', '/iclock/registry?SN=SN0001')->assertSeeText('OK');

        $this->assertTrue(RequestLog::where('path', '/iclock/registry')->exists());
    }

    public function test_idle_polls_are_not_logged_but_uploads_are_and_templates_are_masked()
    {
        $this->approvedDevice();
        $sim = $this->sim();

        $sim->poll();
        $sim->poll();
        $this->assertSame(0, RequestLog::where('path', '/iclock/getrequest')->count());

        $sim->upload('OPERLOG', ["FP PIN=1\tFID=0\tSize=40\tValid=1\tTMP=" . str_repeat('QUJD', 10)]);
        $log = RequestLog::where('table_name', 'OPERLOG')->first();
        $this->assertStringContainsString('TMP=[40 chars]', $log->body);
        $this->assertSame(200, (int) $log->response_code);
    }

    public function test_request_log_can_be_switched_off()
    {
        config(['adms.request_log.enabled' => false]);
        $this->approvedDevice();

        $this->sim()->handshake();
        $this->assertSame(0, RequestLog::count());
    }
}
