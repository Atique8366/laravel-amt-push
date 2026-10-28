<?php

namespace BiometricPush\Adms\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceTemplate;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Tests\DeviceSimulator as Sim;
use BiometricPush\Adms\Tests\Fixtures\AttendanceHandler;
use BiometricPush\Adms\Tests\Fixtures\Employee;
use BiometricPush\Adms\Tests\TestCase;

/**
 * The whole library wired into a host app the way the README describes it, with two
 * simulated devices talking HTTP: registration, handshake, user import + linking,
 * punches → app attendance, employee sync (read-first), enroll once / punch anywhere,
 * removal behind the upload gate.
 */
class FullLifecycleTest extends TestCase
{
    protected function defineAppTables(): void
    {
        foreach (['attendances', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('full_name');
            $t->string('rfid')->nullable();
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->string('employee_id');
            $t->string('day');
            $t->string('check_in');
            $t->string('check_out')->nullable();
            $t->string('method')->nullable();
        });
        Employee::insert([
            ['id' => 1, 'code' => '1001', 'full_name' => 'Jane Doe', 'rfid' => null],
            ['id' => 2, 'code' => '1002', 'full_name' => 'Ali Khan', 'rfid' => '7788'],
            ['id' => 3, 'code' => '1003', 'full_name' => 'Sara Malik', 'rfid' => null],
        ]);
        AttendanceHandler::$failFor = [];

        config([
            'adms.employees' => [
                'resolver' => \BiometricPush\Adms\Resolvers\ConfigEmployeeResolver::class,
                'mode' => 'model', 'model' => Employee::class, 'key' => 'id', 'pin_column' => 'code',
                'name_column' => 'full_name', 'card_column' => 'rfid', 'password_column' => null,
            ],
            'adms.punches.handler' => AttendanceHandler::class,
        ]);
    }

    public function test_full_device_lifecycle_across_two_devices()
    {
        $head = $this->sim('HEAD0001');
        $branch = $this->sim('BRCH0002');

        // 1. First contact: unknown device is held as pending until an admin approves it.
        $head->handshake()->assertStatus(401);
        $this->assertSame('pending', Device::where('serial_number', 'HEAD0001')->value('registration_status'));
        $this->artisan('adms:approve', ['serial' => 'HEAD0001', '--name' => 'Head office'])->assertExitCode(0);
        $this->artisan('adms:approve', ['serial' => 'BRCH0002', '--name' => 'Branch'])->assertExitCode(0);

        // 2. Handshake + capability upload.
        $this->assertStringContainsString('GET OPTION FROM: HEAD0001', $head->handshake()->getContent());
        $head->uploadOptions();
        $branch->handshake();
        $branch->uploadOptions();

        // 3. The head-office device already has people: they are imported and linked by code.
        $head->upload('OPERLOG', [
            Sim::userLine('1001', 'Jane Doe', 14, '9999'),   // a device admin
            Sim::userLine('1002', 'Ali Khan'),
            Sim::userLine('5555', 'Visitor'),                // not an employee
        ]);
        $this->assertSame('1', DeviceUser::where('pin', '1001')->value('employee_id'));
        $this->assertSame('2', DeviceUser::where('pin', '1002')->value('employee_id'));
        $this->assertNull(DeviceUser::where('pin', '5555')->value('employee_id'));

        // 4. Punches become attendance in the app.
        $head->upload('ATTLOG', [
            "1001\t2026-10-27 08:58:00\t0\t1\t0\t0\t0",
            "1002\t2026-10-27 09:03:00\t0\t15\t0\t0\t0",
            "5555\t2026-10-27 09:10:00\t0\t1\t0\t0\t0",
            "1001\t2026-10-27 17:30:00\t1\t1\t0\t0\t0",
        ], '4');
        $this->assertSame(2, DB::table('attendances')->count());
        $jane = DB::table('attendances')->where('employee_id', '1')->first();
        $this->assertSame(['08:58:00', '17:30:00'], [$jane->check_in, $jane->check_out]);
        $this->assertSame('face', DB::table('attendances')->where('employee_id', '2')->value('method'));
        $this->assertSame('unmatched', Punch::where('pin', '5555')->value('process_result'));

        // 5. New hire Sara is synced to the head office: the device is read first, then written.
        $result = Adms::syncEmployee(3, 'HEAD0001');
        $read = Command::find($result['HEAD0001']['command_id']);
        $this->assertSame('DATA QUERY USERINFO', $read->command);
        $head->poll();
        $head->upload('USERINFO', [Sim::userLine('1001', 'Jane Doe', 14, '9999'), Sim::userLine('1002', 'Ali Khan'),
            Sim::userLine('5555', 'Visitor')], null);
        $head->answer([$read->id => 3]);
        $head->poll();
        $this->assertCount(1, $head->received);
        $this->assertStringStartsWith("DATA UPDATE USERINFO PIN=1003\tName=Sara Malik\tPri=0", reset($head->received));
        $head->answerAll();
        $this->assertSame('synced', DeviceUser::where('pin', '1003')->value('sync_status'));
        $this->assertSame('14', DeviceUser::where('pin', '1001')->value('privilege'), 'existing admin untouched');

        // 6. Sara enrolls finger + face at the head office; the device reports and uploads them.
        $head->upload('OPERLOG', ["OPLOG 6\t0\t2026-10-27 10:00:00\t1003\t1\t0\t0"]);
        $head->poll();
        $this->assertNotEmpty($head->received, 'templates read back after the enrollment');
        $head->upload('BIODATA', [
            "BIODATA Pin=1003\tNo=6\tIndex=0\tValid=1\tDuress=0\tType=1\tMajorVer=10\tMinorVer=0\tFormat=0\tTmp=" . Sim::template('sara-fp'),
            "BIODATA Pin=1003\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=2\tMajorVer=7\tMinorVer=0\tFormat=0\tTmp=" . Sim::template('sara-face'),
        ], null);
        $head->answerAll(1);
        $this->assertCount(2, Adms::templates(3));

        // 7. Enroll once, punch anywhere: copy Sara to the branch device.
        $copy = Adms::copyBiometrics(3, ['BRCH0002']);
        $branchRead = Command::find($copy['BRCH0002']['command_id']);
        $branch->poll();
        $branch->upload('USERINFO', [Sim::userLine('2001', 'Branch Staff')], null);
        $branch->answer([$branchRead->id => 1]);
        $branch->poll();
        $sent = array_values($branch->received);
        $this->assertCount(3, $sent, 'user record, then fingerprint and face');
        $this->assertStringStartsWith('DATA UPDATE USERINFO PIN=1003', $sent[0]);
        // algorithm 10 fingerprint / 7 face → the legacy FINGERTMP / FACE tables (BIODATA is for 12+)
        $this->assertStringStartsWith("DATA UPDATE FINGERTMP PIN=1003\tFID=6", $sent[1]);
        $this->assertStringStartsWith("DATA UPDATE FACE PIN=1003\tFID=0", $sent[2]);
        $branch->answerAll();
        $branchId = Device::where('serial_number', 'BRCH0002')->value('id');
        $this->assertSame(2, DeviceTemplate::where('device_id', $branchId)->where('status', 'success')->count());

        // 8. Sara punches at the branch: same employee, same attendance day.
        $branch->punch('1003', '2026-10-27 18:10:00', '15');
        $sara = DB::table('attendances')->where('employee_id', '3')->first();
        $this->assertSame('18:10:00', $sara->check_in);

        // 9. Sara leaves: removal waits until the branch has uploaded every punch it holds.
        $remove = Adms::removeEmployee(3, 'BRCH0002');
        $deleteId = $remove['BRCH0002']['command_id'];
        $branch->poll('Ver 8.0.4.3,2,1,2,192.168.1.202,10,7,12,1,111');   // device holds 2 punches, 1 stored
        $this->assertSame([], $branch->received);
        $branch->punch('2001', '2026-10-27 18:20:00');
        $branch->poll();
        $this->assertSame([$deleteId => 'DATA DELETE USERINFO PIN=1003'], $branch->received);
        $branch->answerAll();
        $this->assertSame('removed', DeviceUser::where('device_id', $branchId)->where('pin', '1003')->value('sync_status'));
        $this->assertSame(2, DeviceTemplate::where('device_id', $branchId)->where('status', 'missing')->count());

        // 10. Copying Sara back restores user and templates without force.
        Adms::copyBiometrics(3, ['BRCH0002']);
        $branch->poll();
        $branch->upload('USERINFO', [Sim::userLine('2001', 'Branch Staff')], null);
        $branch->answerAll(1);
        $branch->poll();
        $this->assertCount(3, $branch->received);
        $branch->answerAll();
        $this->assertSame('synced', DeviceUser::where('device_id', $branchId)->where('pin', '1003')->value('sync_status'));
        $this->assertSame(2, DeviceTemplate::where('device_id', $branchId)->where('status', 'success')->count());

        // Nothing left waiting anywhere.
        $this->assertSame(0, Command::whereIn('status', ['queued', 'sent'])->count());
    }

    public function test_read_only_device_still_collects_but_never_changes_anything()
    {
        $this->approvedDevice('SHARED01', ['read_only' => true]);
        $sim = $this->sim('SHARED01');

        $sim->upload('OPERLOG', [Sim::userLine('1001', 'Jane Doe'), "OPLOG 6\t0\t2026-10-27 10:00:00\t1001\t1\t0\t0"]);
        $sim->punch('1001', '2026-10-27 09:00:00');

        $this->assertSame(1, DB::table('attendances')->count(), 'punches still become attendance');
        $this->assertSame(['SHARED01' => ['skipped' => 'Device SHARED01 is read-only: only reading commands are allowed. Turn read_only off to change it.']],
            Adms::syncEmployee(3, 'SHARED01'));
        $this->assertSame(0, Command::count(), 'no enrollment read-back queued either');
    }
}
