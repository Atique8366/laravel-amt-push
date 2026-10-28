<?php

namespace BiometricPush\AmtPush\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\AmtPush\Events\BiometricsCaptured;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\AdminLog;
use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceBiometric;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Tests\FakeTerminal;
use BiometricPush\AmtPush\Tests\TestCase;

class UsersAndBiometricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amt-push.devices.set_time_on_connect' => false]);
        $this->approvedDevice();
    }

    public function test_set_user_and_its_confirmation()
    {
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->setUser('1001', 'Jane Doe', ['privilege' => 'Manager']);

        $req = $t->poll();
        $this->assertSame(['1001', 'Set', 'Jane Doe', 'Manager', 'Yes'],
            [$req->get('UserID'), $req->get('Type'), Encoding::fromUtf16($req->get('Name')), $req->get('Privilege'), $req->get('AllowNoCertificate')]);
        $this->assertFalse($req->has('Card'), 'cards on the device are not touched');
        $this->assertSame('queued', DeviceUser::where('user_id', '1001')->value('sync_status'));

        $t->answer('SetUserData', ['UserID' => '1001', 'Type' => 'Set', 'Result' => 'OK']);
        $u = DeviceUser::where('user_id', '1001')->first();
        $this->assertSame(['synced', 'Jane Doe', 'Manager'], [$u->sync_status, $u->name, $u->privilege]);
        $this->assertSame('succeeded', $cmd->fresh()->status);
    }

    public function test_no_certificates_is_a_final_failure()
    {
        $t = $this->terminal()->online();
        $cmd = Amt::device('AMT800-0001')->setUser('5', 'Bob', ['allow_no_certificate' => false]);
        $t->poll();
        $t->answer('SetUserData', ['Result' => 'Fail', 'Error' => 'No Certificates']);

        $this->assertSame('failed', $cmd->fresh()->status);
        $this->assertSame('failed', DeviceUser::where('user_id', '5')->value('sync_status'));
        $this->assertSame('device replied: Fail No Certificates', DeviceUser::where('user_id', '5')->value('last_error'));
    }

    public function test_invalid_user_input_is_refused_before_queueing()
    {
        $d = Amt::device('AMT800-0001');
        foreach ([['0', []], ['123456789', []], ['abc', []], ['5', ['privilege' => 'Boss']]] as $case) {
            try {
                $d->setUser($case[0], 'X', $case[1]);
                $this->fail('accepted ' . json_encode($case));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, Command::count());
    }

    public function test_remove_user_marks_removed_and_templates_missing()
    {
        $t = $this->terminal()->online();
        $t->punch(1, '1001');
        $bio = app(\BiometricPush\AmtPush\Services\BiometricService::class);
        $bio->store(Device::first(), '1001', 'finger', 0, 'RklOR0VS');

        $d = Amt::device('AMT800-0001');
        $first = $d->removeUser('1001');
        $this->assertSame($first->id, $d->removeUser('1001')->id, 'no second delete while one waits');

        $req = $t->poll();
        $this->assertSame(['SetUserData', 'Delete'], [$req->get('Request'), $req->get('Type')]);
        $t->answer('SetUserData', ['Result' => 'OK']);

        $this->assertSame('removed', DeviceUser::value('sync_status'));
        $this->assertSame('missing', DeviceBiometric::value('status'));
    }

    public function test_user_listing_imports_users_and_detects_deleted_ones()
    {
        $t = $this->terminal()->online();
        $t->punch(1, '9');   // user 9 known from a punch...
        DeviceUser::query()->update(['sync_status' => 'synced']);

        Amt::device('AMT800-0001')->refreshUsers();
        $t->poll();
        $req = $t->answer('GetFirstUserData', ['UserID' => '1', 'Name' => FakeTerminal::name('Admin'), 'Privilege' => 'Administrator',
            'Depart' => 0, 'Enabled' => 'Yes', 'Fingers' => '5', 'FaceEnrolled' => 'Yes', 'More' => 'Yes', 'Result' => 'OK']);
        $this->assertSame('GetNextUserData', $req->get('Request'));
        $t->answer('GetNextUserData', ['UserID' => '2', 'Name' => FakeTerminal::name('محمد'), 'Privilege' => 'User', 'Fingers' => '0',
            'FaceEnrolled' => 'No', 'More' => 'No', 'Result' => 'OK']);

        $admin = DeviceUser::where('user_id', '1')->first();
        $this->assertSame(['Admin', 'Administrator', [0, 1], true], [$admin->name, $admin->privilege, $admin->enrolledFingers(), $admin->face_enrolled]);
        $this->assertSame('محمد', DeviceUser::where('user_id', '2')->value('name'));
        $this->assertSame('removed', DeviceUser::where('user_id', '9')->value('sync_status'), '...but not on the device any more');
    }

    public function test_enrollment_on_the_device_reads_the_templates_back()
    {
        Event::fake([BiometricsCaptured::class]);
        config(['amt-push.employees.mode' => 'pin']);
        $t = $this->terminal()->online();

        $ack = $t->adminLog(31, 'EnrollFP', '1001');
        $this->assertSame(['AdminLog_v2', 'A31', 'OK'], [$ack->get('Response'), $ack->get('TransID'), $ack->get('Result')]);
        $this->assertTrue(AdminLog::first()->handled);

        $req = $t->lastRequest();
        $this->assertSame(['GetFingerData', '0'], [$req->get('Request'), $req->get('FingerNo')]);
        $req = $t->answer('GetFingerData', ['UserID' => '1001', 'FingerNo' => '0', 'Duress' => 'No', 'FingerData' => 'RklOR0VSMA==']);
        for ($slot = 1; $slot < 10; $slot++) {
            $this->assertSame((string) $slot, $req->get('FingerNo'));
            $req = $t->answer('GetFingerData', $slot === 1 ? ['UserID' => '1001', 'FingerNo' => '1', 'Duress' => 'Yes', 'FingerData' => 'RklOR0VSMQ=='] : ['Result' => 'Fail']);
        }
        $this->assertNull($req, 'only fingers for EnrollFP');

        $templates = Amt::templates(1001);
        $this->assertSame([[0, false], [1, true]], $templates->map(function ($b) {
            return [$b->slot, $b->duress];
        })->all());
        Event::assertDispatchedTimes(BiometricsCaptured::class, 2);

        // menu access / unknown user: nothing to read
        $t->adminLog(32, 'EnterMenu', '1');
        $t->adminLog(33, 'EnrollFace', '0');
        $this->assertNull($t->poll());
    }

    public function test_user_data_strategy_reads_only_enrolled_templates()
    {
        config(['amt-push.biometrics.pull_strategy' => 'user_data']);
        $t = $this->terminal()->online();
        $t->adminLog(1, 'EnrollFace', '7');

        $req = $t->lastRequest();
        $this->assertSame('GetUserData', $req->get('Request'));
        $req = $t->answer('GetUserData', ['UserID' => '7', 'Name' => FakeTerminal::name('Sara'), 'Fingers' => (string) (1 << 4),
            'FaceEnrolled' => 'Yes', 'Result' => 'OK']);
        $this->assertSame('GetFaceData', $req->get('Request'), 'EnrollFace: only the face is read');
        $t->answer('GetFaceData', ['UserID' => '7', 'FaceEnrolled' => 'Yes', 'FaceData' => 'RkFDRQ==', 'Result' => 'OK']);

        $this->assertSame('face', Biometric::value('type'));
        $this->assertSame('d:1:7', Biometric::value('owner_key'), 'unlinked user: kept per device user');

        Amt::device('AMT800-0001')->linkUser('7', 70);
        $this->assertSame('e:70', Biometric::value('owner_key'));
    }

    public function test_an_empty_slot_removes_only_a_template_this_device_was_the_source_of()
    {
        $bio = app(\BiometricPush\AmtPush\Services\BiometricService::class);
        $device = Device::first();
        $tpl = $bio->store($device, '7', 'finger', 2, 'VEVNUExBVEU=');
        $other = $this->approvedDevice('AMT800-0002');
        DeviceBiometric::create(['device_id' => $other->id, 'biometric_id' => $tpl->id, 'user_id' => '7', 'status' => 'success']);

        $bio->markEmptyOnDevice($device, '7', 'finger', 2);
        $this->assertNotNull($tpl->fresh(), 'still on another device: kept');

        DeviceBiometric::where('device_id', $other->id)->delete();
        $bio->markEmptyOnDevice($device, '7', 'finger', 2);
        $this->assertNull($tpl->fresh());
    }
}
