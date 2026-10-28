<?php

namespace BiometricPush\Adms\Tests\Unit;

use Carbon\Carbon;
use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Tests\TestCase;

class ModelsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_timezone_minutes()
    {
        $this->assertSame(300, (new Device(['timezone_offset' => '+05:00']))->timezoneMinutes());
        $this->assertSame(-210, (new Device(['timezone_offset' => '-03:30']))->timezoneMinutes());
        $this->assertSame(330, (new Device(['timezone_offset' => '+0530']))->timezoneMinutes());
        $this->assertSame(480, (new Device(['timezone_offset' => '8']))->timezoneMinutes());
        $this->assertSame(0, (new Device(['timezone_offset' => 'garbage']))->timezoneMinutes());
    }

    public function test_timezone_falls_back_to_config_then_app_timezone()
    {
        config(['adms.devices.timezone_offset' => '+03:00']);
        $this->assertSame(180, (new Device())->timezoneMinutes());

        config(['adms.devices.timezone_offset' => null, 'app.timezone' => 'Asia/Karachi']);
        $this->assertSame(300, (new Device())->timezoneMinutes());
    }

    public function test_can_push_needs_approval_and_push_enabled()
    {
        $this->assertFalse((new Device(['registration_status' => 'pending', 'push_enabled' => true]))->canPush());
        $this->assertFalse((new Device(['registration_status' => 'blocked', 'push_enabled' => true]))->canPush());
        $this->assertFalse((new Device(['registration_status' => 'approved', 'push_enabled' => false]))->canPush());
        $this->assertTrue((new Device(['registration_status' => 'approved', 'push_enabled' => true]))->canPush());
    }

    public function test_is_online_uses_three_poll_intervals_with_a_60_second_minimum()
    {
        Carbon::setTestNow('2026-10-27 10:00:00');

        $this->assertFalse((new Device(['delay_seconds' => 10]))->isOnline());
        $this->assertTrue((new Device(['delay_seconds' => 10, 'last_seen_at' => '2026-10-27 09:59:05']))->isOnline());
        $this->assertFalse((new Device(['delay_seconds' => 10, 'last_seen_at' => '2026-10-27 09:58:59']))->isOnline());
        $this->assertTrue((new Device(['delay_seconds' => 60, 'last_seen_at' => '2026-10-27 09:57:30']))->isOnline());
    }

    public function test_table_prefix_is_configurable()
    {
        $this->assertSame('adms_devices', (new Device())->getTable());

        config(['adms.table_prefix' => 'push_']);
        $this->assertSame('push_devices', (new Device())->getTable());
        $this->assertSame('push_commands', Command::tableName());
    }

    public function test_command_return_meaning_and_safe_text()
    {
        $this->assertSame('Success', Command::returnMeaning(0));
        $this->assertSame('Success (5 records)', Command::returnMeaning(5));
        $this->assertSame('User not found on device', Command::returnMeaning(-10));
        $this->assertSame('Error -77', Command::returnMeaning(-77));
        $this->assertNull(Command::returnMeaning(null));

        $cmd = new Command(['command' => "DATA UPDATE USERINFO PIN=1\tName=A\tPasswd=1234\tCard=0"]);
        $this->assertSame("DATA UPDATE USERINFO PIN=1\tName=A\tPasswd=***\tCard=0", $cmd->safeCommand());

        $tpl = new Command(['command' => "DATA UPDATE FINGERTMP PIN=1\tTMP=" . str_repeat('A', 30)]);
        $this->assertSame("DATA UPDATE FINGERTMP PIN=1\tTMP=[30 chars]", $tpl->safeCommand());
    }

    public function test_role_names_and_template_keys()
    {
        $this->assertSame('super_admin', DeviceUser::roleName('14'));
        $this->assertSame('user', DeviceUser::roleName(0));
        $this->assertSame('level_3', DeviceUser::roleName(3));
        $this->assertNull(DeviceUser::roleName(''));

        $this->assertSame('face', BiometricTemplate::typeName(2));
        $this->assertSame('type_42', BiometricTemplate::typeName(42));
        $this->assertSame('e:42', BiometricTemplate::employeeKey('42'));
        $this->assertSame('d:3:1001', BiometricTemplate::deviceUserKey(3, '1001'));
    }

    public function test_verify_mode_name()
    {
        $this->assertSame('face', (new Punch(['verify_mode' => '15']))->verifyModeName());
        $this->assertSame('finger', (new Punch(['verify_mode' => '1']))->verifyModeName());
        $this->assertSame('other', (new Punch(['verify_mode' => '99']))->verifyModeName());
        $this->assertNull((new Punch())->verifyModeName());
    }
}
