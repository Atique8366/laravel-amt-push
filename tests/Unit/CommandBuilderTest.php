<?php

namespace BiometricPush\AmtPush\Tests\Unit;

use BiometricPush\AmtPush\Models\Biometric;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Protocol\CommandBuilder;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Protocol\Frame;
use BiometricPush\AmtPush\Tests\TestCase;

class CommandBuilderTest extends TestCase
{
    /** @var Device */
    private $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->approvedDevice('B1', ['timezone' => 'Asia/Karachi']);
    }

    private function build(string $request, array $payload = []): Frame
    {
        $xml = (new CommandBuilder())->build(new Command(['request' => $request, 'payload' => $payload]), $this->device);

        return Frame::parse($xml);
    }

    public function test_set_user_sends_only_the_given_fields()
    {
        $f = $this->build('SetUserData', ['user_id' => '1001', 'type' => 'Set', 'name' => 'Jane']);

        $this->assertSame(['Request', 'UserID', 'Type', 'Name'], array_keys($f->toArray()),
            'no Card / QR / PWD / Privilege sent, so the device keeps them');
        $this->assertSame('Jane', Encoding::fromUtf16($f->get('Name')));
    }

    public function test_set_user_with_every_field()
    {
        $f = $this->build('SetUserData', [
            'user_id' => '7', 'type' => 'Set', 'name' => 'Ali', 'privilege' => 'Manager', 'department' => 3, 'enabled' => false,
            'timesets' => [1, 2, 0, 0, 0], 'period' => ['2026-01-01', '2026-12-31'], 'card' => 123456, 'password' => '12-34',
            'qr' => 0, 'allow_no_certificate' => true,
        ]);

        $this->assertSame('Manager', $f->get('Privilege'));
        $this->assertSame('3', $f->get('Depart'));
        $this->assertSame('No', $f->get('Enabled'));
        $this->assertSame('1', $f->get('TimeSet1'));
        $this->assertSame('2', $f->get('TimeSet2'));
        $this->assertSame('Yes', $f->get('UserPeriod_Used'));
        $this->assertSame((string) Encoding::packDate(2026, 12, 31), $f->get('UserPeriod_End'));
        $this->assertSame(123456, Encoding::fromInt32($f->get('Card')));
        $this->assertSame('1234', $f->get('PWD'));
        $this->assertSame('AAAAAA==', $f->get('QR'));
        $this->assertSame('Yes', $f->get('AllowNoCertificate'));
    }

    public function test_delete_user()
    {
        $f = $this->build('SetUserData', ['user_id' => '9', 'type' => 'Delete', 'name' => 'ignored']);

        $this->assertSame(['Request' => 'SetUserData', 'UserID' => '9', 'Type' => 'Delete'], $f->toArray());
    }

    public function test_templates_are_read_at_send_time()
    {
        $fp = Biometric::create(['owner_key' => 'e:1', 'type' => 'finger', 'slot' => 3, 'duress' => true, 'template' => 'RklOR0VS', 'template_hash' => sha1('RklOR0VS')]);
        $face = Biometric::create(['owner_key' => 'e:1', 'type' => 'face', 'slot' => 0, 'template' => 'RkFDRQ==', 'template_hash' => sha1('RkFDRQ==')]);
        $palm = Biometric::create(['owner_key' => 'e:1', 'type' => 'palm', 'slot' => 1, 'template' => 'UEFMTQ==', 'template_hash' => sha1('UEFMTQ==')]);

        $f = $this->build('SetFingerData', ['user_id' => '5', 'biometric_id' => $fp->id]);
        $this->assertSame(['3', '1', '1', 'RklOR0VS', 'User'], [$f->get('FingerNo'), $f->get('DuplicationCheck'), $f->get('Duress'), $f->get('FingerData'), $f->get('Privilege')]);

        $f = $this->build('SetFaceData', ['user_id' => '5', 'biometric_id' => $face->id, 'privilege' => 'Administrator']);
        $this->assertSame(['Yes', 'RkFDRQ==', 'Administrator'], [$f->get('DuplicationCheck'), $f->get('FaceData'), $f->get('Privilege')]);

        $f = $this->build('SetPalmData', ['user_id' => '5', 'biometric_id' => $palm->id]);
        $this->assertSame(['1', 'UEFMTQ=='], [$f->get('PalmNo'), $f->get('PalmData')]);

        $f = $this->build('SetFingerData', ['user_id' => '5', 'delete' => true, 'finger_no' => 2]);
        $this->assertFalse($f->has('FingerData'), 'no FingerData = delete that finger');
    }

    public function test_wrong_template_type_or_missing_user_fails_the_build()
    {
        $face = Biometric::create(['owner_key' => 'e:1', 'type' => 'face', 'slot' => 0, 'template' => 'RkFDRQ==', 'template_hash' => 'x']);

        foreach ([['SetFingerData', ['user_id' => '5', 'biometric_id' => $face->id]], ['GetFingerData', ['user_id' => '5', 'finger_no' => 10]],
                  ['SetUserData', ['name' => 'no id']], ['LockControl', ['mode' => 9]], ['EnableDevice', []]] as $case) {
            try {
                $this->build($case[0], $case[1]);
                $this->fail($case[0] . ' should not build');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_photo_size_limit()
    {
        $this->assertSame('3', $this->build('SetUserPhoto', ['user_id' => '1', 'photo' => base64_encode('JPG')])->get('PhotoSize'));

        config(['amt-push.biometrics.max_photo_bytes' => 2]);
        $this->expectException(\RuntimeException::class);
        $this->build('SetUserPhoto', ['user_id' => '1', 'photo' => base64_encode('JPG')]);
    }

    public function test_log_requests()
    {
        $f = $this->build('GetFirstGlog', ['begin_log_pos' => 25, 'user_id' => '7', 'start_time' => '2026-10-01 00:00:00']);
        $this->assertSame(['25', '7', '2026-10-01-T00:00:00Z'], [$f->get('BeginLogPos'), $f->get('UserID'), $f->get('StartTime')]);
        $this->assertFalse($f->has('EndTime'));

        $this->assertSame('26', $this->build('GetNextGlog', ['begin_log_pos' => 26])->get('BeginLogPos'));
        $this->assertSame(['Request' => 'GetGlogPosInfo'], $this->build('GetGlogPosInfo')->toArray());
        $this->assertSame(['Request' => 'EmptyTimeLog'], $this->build('EmptyTimeLog')->toArray());
    }

    public function test_set_time_uses_the_device_timezone_and_unpadded_format()
    {
        $time = $this->build('SetTime')->get('Time');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{1,2}-\d{1,2}-T\d{2}:\d{2}:\d{2}Z$/', $time);
        $this->assertLessThan(5, abs(\BiometricPush\AmtPush\Protocol\DeviceTime::parse($time, 'Asia/Karachi')->diffInSeconds(now(), true)));
    }

    public function test_device_requests_and_raw_requests()
    {
        $this->assertSame('No', $this->build('EnableDevice', ['enable' => false])->get('Enable'));
        $this->assertSame('5', $this->build('LockControl', ['mode' => 5])->get('Mode'));
        $f = $this->build('SetDepartment', ['dept_no' => 2, 'name' => 'Sales']);
        $this->assertSame('Sales', Encoding::fromUtf16($f->get('Data')), 'SetDepartment uses <Data>, not <Name>');

        $raw = $this->build('SetDeviceInfo', ['tags' => ['ParamName' => 'SoundVolume', 'Value' => 5]]);
        $this->assertSame(['Request' => 'SetDeviceInfo', 'ParamName' => 'SoundVolume', 'Value' => '5'], $raw->toArray());
    }
}
