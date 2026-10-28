<?php

namespace BiometricPush\AmtPush\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceBiometric;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Tests\FakeTerminal;
use BiometricPush\AmtPush\Tests\Fixtures\AttendanceHandler;
use BiometricPush\AmtPush\Tests\Fixtures\Employee;
use BiometricPush\AmtPush\Tests\TestCase;

/**
 * The library wired into a host app the way the README describes, two terminals: approval,
 * login, user listing + linking, punches → attendance, new-hire sync, enrollment read-back,
 * enroll once / punch anywhere, removal, safe log clear.
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
            $t->string('pin')->nullable();
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
            ['id' => 1, 'code' => '1001', 'full_name' => 'Jane Doe', 'pin' => null],
            ['id' => 2, 'code' => '1002', 'full_name' => 'Ali Khan', 'pin' => '2468'],
            ['id' => 3, 'code' => '1003', 'full_name' => 'Sara Malik', 'pin' => null],
        ]);
        AttendanceHandler::$failFor = [];
        config([
            'amt-push.employees' => [
                'resolver' => \BiometricPush\AmtPush\Resolvers\ConfigEmployeeResolver::class,
                'mode' => 'model', 'model' => Employee::class, 'key' => 'id', 'pin_column' => 'code',
                'name_column' => 'full_name', 'password_column' => 'pin', 'card_column' => null, 'department_column' => null,
            ],
            'amt-push.punches.handler' => AttendanceHandler::class,
        ]);
    }

    public function test_two_terminals_from_first_contact_to_leaver()
    {
        $head = $this->terminal('HEAD-0001');
        $branch = $this->terminal('BRCH-0002');

        // 1. first contact: pending until an admin approves
        $this->assertSame('Fail', $head->register()->get('Result'));
        $this->artisan('amt:approve', ['serial' => 'HEAD-0001', '--name' => 'Head office'])->assertExitCode(0);
        $this->artisan('amt:approve', ['serial' => 'BRCH-0002', '--name' => 'Branch'])->assertExitCode(0);
        $this->assertSame('OK', $head->register()->get('Result'));
        $this->assertSame('SetTime', $head->lastRequest()->get('Request'));
        $head->answer('SetTime');
        $branch->online();

        // 2. read the people already enrolled at the head office: linked by employee code
        Amt::device('HEAD-0001')->refreshUsers();
        $head->poll();
        $head->answer('GetFirstUserData', ['UserID' => '1001', 'Name' => FakeTerminal::name('Jane Doe'), 'Privilege' => 'Administrator',
            'Fingers' => '1', 'FaceEnrolled' => 'No', 'More' => 'Yes', 'Result' => 'OK']);
        $head->answer('GetNextUserData', ['UserID' => '1002', 'Name' => FakeTerminal::name('Ali Khan'), 'Privilege' => 'User',
            'More' => 'Yes', 'Result' => 'OK']);
        $head->answer('GetNextUserData', ['UserID' => '9000', 'Name' => FakeTerminal::name('Visitor'), 'More' => 'No', 'Result' => 'OK']);
        $this->assertSame(['1001' => '1', '1002' => '2', '9000' => null],
            DeviceUser::orderBy('user_id')->pluck('employee_id', 'user_id')->all());

        // 3. live punches become attendance
        $head->punch(101, '1001', '2026-10-28-T08:58:00Z', 'FP');
        $head->punch(102, '1002', '2026-10-28-T09:03:00Z', 'FACE');
        $head->punch(103, '9000', '2026-10-28-T09:10:00Z');
        $head->punch(104, '1001', '2026-10-28-T17:30:00Z', 'FP');
        $jane = DB::table('attendances')->where('employee_id', '1')->first();
        $this->assertSame(['08:58:00', '17:30:00'], [$jane->check_in, $jane->check_out]);
        $this->assertSame('face', DB::table('attendances')->where('employee_id', '2')->value('method'));
        $this->assertSame('unmatched', Punch::where('user_id', '9000')->value('process_result'));

        // 4. new hire Sara: user record from the resolver, only those fields sent
        $sync = Amt::syncEmployee(3, 'HEAD-0001');
        $req = $head->poll();
        $this->assertSame(['SetUserData', '1003', 'Sara Malik'], [$req->get('Request'), $req->get('UserID'), Encoding::fromUtf16($req->get('Name'))]);
        $this->assertFalse($req->has('PWD'), 'no PIN for Sara: none sent');
        $head->answer('SetUserData', ['Result' => 'OK']);
        $this->assertSame('synced', DeviceUser::where('user_id', '1003')->value('sync_status'));
        $this->assertSame(1, count($sync['HEAD-0001']['commands']));

        // 5. Sara enrolls a finger and her face at the head office: read back automatically
        $head->adminLog(7, 'EnrollFP', '1003');
        $req = $head->lastRequest();
        for ($slot = 0; $slot < 10; $slot++) {
            $this->assertSame('GetFingerData', $req->get('Request'));
            $req = $head->answer('GetFingerData', $slot === 6
                ? ['UserID' => '1003', 'FingerNo' => '6', 'Duress' => 'No', 'FingerData' => base64_encode('sara-finger-6')]
                : ['Result' => 'Fail']);
        }
        $head->adminLog(8, 'EnrollFace', '1003');
        $req = $head->lastRequest();
        $this->assertSame('GetFaceData', $req->get('Request'));
        $head->answer('GetFaceData', ['UserID' => '1003', 'FaceEnrolled' => 'Yes', 'FaceData' => base64_encode(str_repeat('face', 9000)), 'Result' => 'OK']);
        $this->assertSame(['finger', 'face'], Amt::templates(3)->pluck('type')->all());

        // 6. enroll once, punch anywhere: copy Sara to the branch (user, then finger, then face)
        Amt::copyBiometrics(3, ['BRCH-0002']);
        $req = $branch->poll();
        $sent = [];
        while ($req) {
            $sent[] = $req->get('Request') . ($req->get('FingerNo') !== '' ? ':' . $req->get('FingerNo') : '');
            if ($req->get('Request') === 'SetFaceData') {
                $this->assertSame(base64_encode(str_repeat('face', 9000)), $req->get('FaceData'));
            }
            $req = $branch->answer($req->get('Request'), ['Result' => 'OK']);
        }
        $this->assertSame(['SetUserData', 'SetFingerData:6', 'SetFaceData'], $sent);
        $branchId = Device::where('serial_number', 'BRCH-0002')->value('id');
        $this->assertSame(2, DeviceBiometric::where('device_id', $branchId)->where('status', 'success')->count());
        $this->assertCount(1, Amt::copyBiometrics(3, ['BRCH-0002'])['BRCH-0002']['commands'],
            'copying again re-sends only the user record: the templates there are current');

        // 7. Sara punches at the branch: same employee, same attendance day
        $branch->punch(1, '1003', '2026-10-28-T18:10:00Z', 'FACE');
        $this->assertSame('18:10:00', DB::table('attendances')->where('employee_id', '3')->value('check_in'));

        // 8. Sara leaves: removed from every device she is on
        $removal = Amt::removeEmployee(3);
        $this->assertSame(['HEAD-0001', 'BRCH-0002'], array_keys($removal));
        foreach ([$head, $branch] as $t) {
            $req = $t->poll();
            while ($req) {
                $req = $t->answer($req->get('Request'), ['Result' => 'OK']);
            }
        }
        $this->assertSame(['removed', 'removed'], DeviceUser::where('user_id', '1003')->pluck('sync_status')->all());
        $this->assertSame(0, DeviceBiometric::where('device_id', $branchId)->whereIn('status', ['success', 'on_device'])->count());

        // 9. the head office log is cleared only after everything on it is stored
        $clear = Amt::device('HEAD-0001')->clearAttendance();
        $req = $head->poll();
        $req = $head->answer('GetGlogPosInfo', ['LogCount' => 4, 'Result' => 'OK']);
        $this->assertSame('105', $req->get('BeginLogPos'));
        $req = $head->answer('GetFirstGlog', ['Result' => 'Fail']);
        $this->assertSame('EmptyTimeLog', $req ? $req->get('Request') : null);
        $head->answer('EmptyTimeLog');
        $this->assertSame('succeeded', $clear->fresh()->status);

        $this->assertSame(0, Command::whereIn('status', ['pending', 'sent'])->count(), 'nothing left waiting');
    }
}
