<?php

namespace BiometricPush\Adms\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Resolvers\ConfigEmployeeResolver;
use BiometricPush\Adms\Tests\Fixtures\Employee;
use BiometricPush\Adms\Tests\TestCase;

class ConfigEmployeeResolverTest extends TestCase
{
    protected function defineAppTables(): void
    {
        Schema::dropIfExists('employees');
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code')->nullable();
            $t->string('full_name');
            $t->string('rfid')->nullable();
        });
        Employee::insert([
            ['code' => '1001', 'full_name' => 'Jane Doe', 'rfid' => '555'],
            ['code' => '2002', 'full_name' => 'Ali Khan', 'rfid' => null],
            ['code' => '3003', 'full_name' => 'Twin One', 'rfid' => null],
            ['code' => '3003', 'full_name' => 'Twin Two', 'rfid' => null],
        ]);
    }

    public function test_none_mode_never_links()
    {
        config(['adms.employees.mode' => 'none']);
        $r = new ConfigEmployeeResolver();

        $this->assertNull($r->resolve(new Device(), '1001', 'Jane'));
        $this->assertNull($r->deviceUser(new Device(), '1'));
    }

    public function test_pin_mode_uses_the_pin_as_employee_id()
    {
        config(['adms.employees.mode' => 'pin']);
        $r = new ConfigEmployeeResolver();

        $this->assertSame('77', $r->resolve(new Device(), '77', ''));
        $this->assertSame(['pin' => '77'], $r->deviceUser(new Device(), '77'));
    }

    public function test_model_mode_looks_up_the_pin_column()
    {
        config(['adms.employees' => [
            'mode' => 'model', 'model' => Employee::class, 'key' => 'id', 'pin_column' => 'code',
            'name_column' => 'full_name', 'card_column' => 'rfid', 'password_column' => null,
        ]]);
        $r = new ConfigEmployeeResolver();

        $this->assertSame('1', $r->resolve(new Device(), '1001', 'whatever'));
        $this->assertNull($r->resolve(new Device(), '9999', ''));
        $this->assertNull($r->resolve(new Device(), '3003', ''), 'two employees share the code: never guessed');

        $this->assertSame(['pin' => '1001', 'name' => 'Jane Doe', 'card' => '555'], $r->deviceUser(new Device(), '1'));
        $this->assertNull($r->deviceUser(new Device(), '999'));
    }
}
