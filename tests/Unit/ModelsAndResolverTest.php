<?php

namespace BiometricPush\AmtPush\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Resolvers\ConfigEmployeeResolver;
use BiometricPush\AmtPush\Tests\Fixtures\Employee;
use BiometricPush\AmtPush\Tests\TestCase;

class ModelsAndResolverTest extends TestCase
{
    protected function defineAppTables(): void
    {
        Schema::dropIfExists('employees');
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('full_name');
            $t->string('pin')->nullable();
        });
        Employee::insert([
            ['code' => '1001', 'full_name' => 'Jane Doe', 'pin' => '4321'],
            ['code' => '3003', 'full_name' => 'Twin One', 'pin' => null],
            ['code' => '3003', 'full_name' => 'Twin Two', 'pin' => null],
        ]);
    }

    public function test_finger_and_palm_masks()
    {
        // fingers 0 and 3 enrolled, finger 3 duress (bit 7)
        $u = new DeviceUser(['fingers_mask' => (1 << 0) | (1 << 6) | (1 << 7), 'palms_mask' => 0b10]);

        $this->assertSame([0, 3], $u->enrolledFingers());
        $this->assertSame([1], $u->enrolledPalms());
    }

    public function test_punch_methods_from_action()
    {
        $this->assertSame(['finger'], (new Punch(['action' => 'FP']))->methods());
        $this->assertSame(['face', 'card', 'password'], (new Punch(['action' => 'FACE+CD+PWD']))->methods());
        $this->assertSame([], (new Punch(['action' => 'Tamper']))->methods());
    }

    public function test_table_prefix_and_clock_timezone()
    {
        $this->assertSame('amt_push_devices', (new Device())->getTable());
        config(['amt-push.table_prefix' => 'x_']);
        $this->assertSame('x_commands', \BiometricPush\AmtPush\Models\Command::tableName());

        config(['amt-push.devices.timezone' => null, 'app.timezone' => 'Asia/Karachi']);
        $this->assertSame('Asia/Karachi', (new Device())->clockTimezone());
        $this->assertSame('Europe/Berlin', (new Device(['timezone' => 'Europe/Berlin']))->clockTimezone());
    }

    public function test_resolver_modes()
    {
        $r = new ConfigEmployeeResolver();
        $d = new Device();

        $this->assertNull($r->resolve($d, '1001', ''));

        config(['amt-push.employees.mode' => 'pin']);
        $this->assertSame('55', $r->resolve($d, '55', ''));
        $this->assertSame(['user_id' => '55'], $r->deviceUser($d, '55'));

        config(['amt-push.employees' => [
            'mode' => 'model', 'model' => Employee::class, 'key' => 'id', 'pin_column' => 'code', 'name_column' => 'full_name',
            'password_column' => 'pin', 'card_column' => null, 'department_column' => null,
        ]]);
        $this->assertSame('1', $r->resolve($d, '1001', ''));
        $this->assertNull($r->resolve($d, '3003', ''), 'two employees share the code: never guessed');
        $this->assertSame(['user_id' => '1001', 'name' => 'Jane Doe', 'password' => '4321'], $r->deviceUser($d, '1'));
        $this->assertNull($r->deviceUser($d, '99'));
    }
}
