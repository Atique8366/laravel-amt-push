<?php

namespace BiometricPush\Adms\Tests\Feature;

use Carbon\Carbon;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\RequestLog;
use BiometricPush\Adms\Tests\TestCase;

class ConsoleAndRoutesTest extends TestCase
{
    public function test_approve_command_registers_and_approves()
    {
        $this->artisan('adms:approve', ['serial' => 'CLI1', '--name' => 'Front door'])->assertExitCode(0);

        $d = Device::where('serial_number', 'CLI1')->first();
        $this->assertSame('approved', $d->registration_status);
        $this->assertSame('Front door', $d->name);

        $this->artisan('adms:approve', ['serial' => 'CLI1', '--block' => true])->assertExitCode(0);
        $this->assertSame('blocked', $d->fresh()->registration_status);
    }

    public function test_devices_command_lists_devices()
    {
        $this->approvedDevice('LIST1');

        $this->artisan('adms:devices')->assertExitCode(0);
    }

    public function test_command_command_queues()
    {
        $this->approvedDevice();

        $this->artisan('adms:command', ['serial' => 'SN0001', 'action' => 'restart'])->assertExitCode(0);
        $this->artisan('adms:command', ['serial' => 'SN0001', 'action' => 'raw', 'raw' => 'SET OPTION Delay=30'])->assertExitCode(0);
        $this->artisan('adms:command', ['serial' => 'SN0001', 'action' => 'nope'])->assertExitCode(1);

        $this->assertSame(['REBOOT', 'SET OPTION Delay=30'], Command::orderBy('id')->pluck('command')->all());
    }

    public function test_process_command_without_handler_is_a_no_op()
    {
        $this->artisan('adms:process')->assertExitCode(0);
    }

    public function test_prune_command()
    {
        $this->approvedDevice();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->sim()->handshake();
        Carbon::setTestNow();
        $this->sim()->handshake();

        $this->artisan('adms:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertSame(1, RequestLog::count());
    }

    public function test_aspx_aliases()
    {
        $this->approvedDevice();

        $this->call('GET', '/iclock/cdata.aspx?SN=SN0001&options=all')->assertStatus(200);
        $this->call('GET', '/iclock/getrequest.aspx?SN=SN0001')->assertStatus(200);
        $this->call('POST', '/iclock/devicecmd.aspx?SN=SN0001')->assertStatus(200);
    }
}
