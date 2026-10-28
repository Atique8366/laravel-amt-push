<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\Message;
use BiometricPush\AmtPush\Tests\TestCase;

class ConsoleTest extends TestCase
{
    public function test_approve_and_block()
    {
        $this->artisan('amt:approve', ['serial' => 'CLI-1', '--name' => 'Gate'])->assertExitCode(0);
        $this->assertSame(['approved', 'Gate'], [Device::value('registration_status'), Device::value('name')]);

        $this->artisan('amt:approve', ['serial' => 'CLI-1', '--block' => true])->assertExitCode(0);
        $this->assertSame('blocked', Device::value('registration_status'));
    }

    public function test_devices_list()
    {
        $this->approvedDevice();
        $this->artisan('amt:devices')->assertExitCode(0);
        $this->artisan('amt:devices', ['--pending' => true])->assertExitCode(0);
    }

    public function test_command_actions()
    {
        $this->approvedDevice();

        foreach (['sync-time', 'users', 'restart', 'disable'] as $action) {
            $this->artisan('amt:command', ['serial' => 'AMT800-0001', 'action' => $action])->assertExitCode(0);
        }
        $this->artisan('amt:command', ['serial' => 'AMT800-0001', 'action' => 'raw', 'request' => 'GetTime'])->assertExitCode(0);
        $this->artisan('amt:command', ['serial' => 'AMT800-0001', 'action' => 'pull'])->assertExitCode(0);
        $this->artisan('amt:command', ['serial' => 'AMT800-0001', 'action' => 'nope'])->assertExitCode(1);

        $this->assertSame(['SetTime', 'GetFirstUserData', 'LockControl', 'EnableDevice', 'GetTime', 'GetGlogPosInfo', 'GetFirstGlog'],
            Command::orderBy('id')->pluck('request')->all());
    }

    public function test_process_and_prune()
    {
        $this->artisan('amt:process')->assertExitCode(0);

        Message::create(['direction' => 'in', 'kind' => 'X', 'payload' => 'old']);
        Message::query()->update(['created_at' => now()->subDays(30)]);
        Message::create(['direction' => 'in', 'kind' => 'X', 'payload' => 'new']);
        $this->artisan('amt:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertSame(['new'], Message::pluck('payload')->all());
    }
}
