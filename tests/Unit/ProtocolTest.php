<?php

namespace BiometricPush\AmtPush\Tests\Unit;

use Illuminate\Support\Carbon;
use BiometricPush\AmtPush\Protocol\DeviceTime;
use BiometricPush\AmtPush\Protocol\Encoding;
use BiometricPush\AmtPush\Protocol\Frame;
use BiometricPush\AmtPush\Protocol\MessageBuilder;
use BiometricPush\AmtPush\Tests\TestCase;

class ProtocolTest extends TestCase
{
    public function test_utf16_names_round_trip_with_terminator()
    {
        foreach (['Jane Doe', 'Muhammad Atique', 'محمد', '张伟', 'José', ''] as $name) {
            $encoded = Encoding::utf16($name);
            $this->assertStringEndsWith("\x00\x00", base64_decode($encoded));
            $this->assertSame($name, Encoding::fromUtf16($encoded));
        }
        // the SDK reference value for "A": 0x41 0x00 + terminator
        $this->assertSame(base64_encode("A\x00\x00\x00"), Encoding::utf16('A'));
        $this->assertSame('', Encoding::fromUtf16('not base64!'));
    }

    public function test_int32_card_values()
    {
        $this->assertSame('AAAAAA==', Encoding::int32(0), 'spec: integer 0 is "AAAAAA=="');
        $this->assertSame(5342354, Encoding::fromInt32(Encoding::int32(5342354)));
        $this->assertSame(4294967295, Encoding::fromInt32(Encoding::int32(-1)));
        $this->assertSame(0, Encoding::fromInt32('AA=='));
    }

    public function test_packed_dates()
    {
        $this->assertSame((25 << 16) + (12 << 8) + 31, Encoding::packDate(2025, 12, 31));
        $this->assertSame(1641503, Encoding::packDate(2025, 12, 31));
        $this->assertSame([2025, 12, 31], Encoding::unpackDate(1641503));
        $this->assertSame(Encoding::packDate(2000, 1, 1), Encoding::packDate(1990, 0, 0), 'clamped to the valid range');
    }

    public function test_device_time_parse_and_format()
    {
        $this->assertSame('2026-04-23 11:00:03', DeviceTime::parse('2026-4-23-T11:00:03Z')->format('Y-m-d H:i:s'));
        $this->assertSame('2013-05-06 11:09:30', DeviceTime::parse('2013-05-06-T11:09:30Z')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-23 11:00:00', DeviceTime::parse('2026-04-23T11:00')->format('Y-m-d H:i:s'));
        $this->assertNull(DeviceTime::parse(''));
        $this->assertNull(DeviceTime::parse('2026-2-30-T10:00:00Z'));
        $this->assertNull(DeviceTime::parse('yesterday'));

        $t = Carbon::create(2026, 5, 8, 14, 30, 45);
        $this->assertSame('2026-05-08-T14:30:45Z', DeviceTime::format($t));
        $this->assertSame('2026-5-8-T14:30:45Z', DeviceTime::formatUnpadded($t));
    }

    public function test_frame_types_names_and_values()
    {
        $f = Frame::parse('<?xml version="1.0"?><Message><TerminalType>F500</TerminalType><DeviceSerialNo>F500-0001</DeviceSerialNo>'
            . '<Event>TimeLog_v2</Event><LogID>24</LogID><UserID_Base36>2Z</UserID_Base36><Photo>Yes</Photo></Message>');

        $this->assertSame('event', $f->type());
        $this->assertSame('TimeLog_v2', $f->name());
        $this->assertSame(24, $f->int('LogID'));
        $this->assertSame('2Z', $f->get('UserID'), 'Base36 alias promoted');
        $this->assertTrue($f->yes('Photo'));
        $this->assertNull($f->opt('Latitude'));
        $this->assertSame('F500-0001', $f->toArray()['DeviceSerialNo']);

        $r = Frame::parse("\xEF\xBB\xBF<Message><Response>SetTime</Response><Result>OK</Result></Message>");
        $this->assertSame('response', $r->type());
        $this->assertTrue($r->isOk());
        $this->assertFalse(Frame::parse('<Message><Response>X</Response><Result>Fail</Result></Message>')->isOk());

        $this->assertNull(Frame::parse('not xml'));
        $this->assertNull(Frame::parse('<Message><Unclosed></Message>'));
    }

    public function test_frames_do_not_resolve_external_entities()
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE m [<!ENTITY x SYSTEM "file:///etc/passwd">]><Message><Request>&x;</Request></Message>';
        $f = Frame::parse($xxe);

        $this->assertTrue($f === null || strpos($f->get('Request'), 'root:') === false);
    }

    public function test_message_builder()
    {
        $xml = MessageBuilder::request('SetUserData')->add('UserID', 7)->add('Skip', null)->add('Enabled', true)
            ->add('Name', 'A & <B>')->addIf(false, 'No', 1)->toXml();
        $f = Frame::parse($xml);

        $this->assertSame('SetUserData', $f->get('Request'));
        $this->assertSame('Yes', $f->get('Enabled'));
        $this->assertSame('A & <B>', $f->get('Name'));
        $this->assertFalse($f->has('Skip'));
        $this->assertFalse($f->has('No'));
        $this->assertStringStartsWith('<?xml version="1.0"?>', $xml);
    }

    public function test_mask_payload_hides_templates_and_photos()
    {
        $xml = '<Message><FingerData>' . str_repeat('QUJD', 10) . '</FingerData><UserID>1</UserID><LogImage>' . str_repeat('A', 20) . '</LogImage></Message>';

        $this->assertSame('<Message><FingerData>[40 chars]</FingerData><UserID>1</UserID><LogImage>[20 chars]</LogImage></Message>',
            Encoding::maskPayload($xml));
    }
}
