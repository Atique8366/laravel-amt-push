<?php

namespace BiometricPush\Adms\Tests\Unit;

use Illuminate\Http\Request;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Support\Protocol;
use BiometricPush\Adms\Tests\TestCase;

class ProtocolTest extends TestCase
{
    public function test_param_is_case_insensitive_and_trimmed()
    {
        $request = Request::create('/iclock/cdata?sn=%20ABC123%20&Table=ATTLOG');

        $this->assertSame('ABC123', Protocol::param($request, 'SN'));
        $this->assertSame('ATTLOG', Protocol::param($request, 'table'));
        $this->assertNull(Protocol::param($request, 'Stamp'));
    }

    public function test_lines_drops_blank_lines_and_carriage_returns()
    {
        $this->assertSame(['a', 'b'], Protocol::lines("a\r\n\r\n  \nb\r\n"));
        $this->assertSame([], Protocol::lines(''));
    }

    public function test_attlog_line_with_all_fields()
    {
        $p = Protocol::parseAttlogLine("1001\t2026-10-27 09:01:02\t0\t15\t7\t0\t0\t1\t36.5");

        $this->assertSame('1001', $p['pin']);
        $this->assertSame('2026-10-27 09:01:02', $p['punched_at']);
        $this->assertSame('0', $p['punch_status']);
        $this->assertSame('15', $p['verify_mode']);
        $this->assertSame('7', $p['work_code']);
        $this->assertSame('1', $p['mask_flag']);
        $this->assertSame('36.5', $p['temperature']);
    }

    public function test_attlog_line_without_seconds_and_with_missing_optional_fields()
    {
        $p = Protocol::parseAttlogLine("7\t2026-10-27 09:01");

        $this->assertSame('2026-10-27 09:01:00', $p['punched_at']);
        $this->assertNull($p['punch_status']);
        $this->assertNull($p['verify_mode']);
    }

    public function test_attlog_line_rejects_missing_pin_bad_time_and_long_pin()
    {
        $this->assertNull(Protocol::parseAttlogLine("\t2026-10-27 09:01:02"));
        $this->assertNull(Protocol::parseAttlogLine("1001\tyesterday"));
        $this->assertNull(Protocol::parseAttlogLine(str_repeat('9', 33) . "\t2026-10-27 09:01:02"));
    }

    public function test_key_values_and_kv_lookup()
    {
        $kv = Protocol::keyValues("~DeviceName=MB20,FWVersion=Ver 8.0.4.3\tUserCount=149\nFPVersion=10,Empty=,=bad");

        $this->assertSame('MB20', $kv['DeviceName']);
        $this->assertSame('149', $kv['UserCount']);
        $this->assertSame('10', Protocol::kv($kv, 'fpversion'));
        $this->assertSame('Ver 8.0.4.3', Protocol::kv($kv, 'FirmVer', 'FWVersion'));
        $this->assertNull(Protocol::kv($kv, 'Empty'));
        $this->assertNull(Protocol::kv($kv, 'Missing'));
    }

    public function test_tab_fields_keep_commas_and_equals_in_values()
    {
        $f = Protocol::tabFields("PIN=1\tName=Doe, Jane\tTMP=abc==\tPri=0");

        $this->assertSame(['PIN' => '1', 'Name' => 'Doe, Jane', 'TMP' => 'abc==', 'Pri' => '0'], $f);
    }

    public function test_result_fields()
    {
        $this->assertSame(['ID' => '12', 'Return' => '-1004', 'CMD' => 'DATA'], Protocol::resultFields('ID=12&Return=-1004&CMD=DATA'));
    }

    public function test_parse_info()
    {
        $info = Protocol::parseInfo('Ver%208.0.4.3,149,47,757,192.168.1.201,10,7,12,3,111');

        $this->assertSame('Ver 8.0.4.3', $info['firmware_version']);
        $this->assertSame(149, $info['user_count']);
        $this->assertSame(47, $info['fp_count']);
        $this->assertSame(757, $info['attlog_count']);
        $this->assertSame('192.168.1.201', $info['device_ip']);
        $this->assertSame('10', $info['fp_algorithm']);
        $this->assertSame('7', $info['face_algorithm']);
        $this->assertSame(3, $info['face_count']);

        $this->assertArrayNotHasKey('device_ip', Protocol::parseInfo('v,1,2,3,not-an-ip'));
    }

    public function test_handshake_options_carry_stamps_and_settings()
    {
        $device = new Device([
            'serial_number' => 'SN9', 'attlog_stamp' => '555', 'operlog_stamp' => '66', 'delay_seconds' => 30,
            'error_delay_seconds' => 60, 'realtime' => false, 'trans_interval' => 2, 'timezone_offset' => '+05:00',
        ]);
        $text = Protocol::handshakeOptions($device);

        $this->assertStringStartsWith("GET OPTION FROM: SN9\n", $text);
        $this->assertStringContainsString("ATTLOGStamp=555\n", $text);
        $this->assertStringContainsString("Stamp=555\n", $text);
        $this->assertStringContainsString("OPERLOGStamp=66\n", $text);
        $this->assertStringContainsString("ATTPHOTOStamp=0\n", $text);
        $this->assertStringContainsString("Delay=30\n", $text);
        $this->assertStringContainsString("Realtime=0\n", $text);
        $this->assertStringContainsString("TimeZone=300\n", $text);
        $this->assertStringContainsString('TransFlag=TransData AttLog', $text);
        $this->assertStringContainsString("PushProtVer=2.4.1\n", $text);
    }

    public function test_mask_biometrics_hides_templates_and_photos()
    {
        $tmp = str_repeat('QUJD', 10);

        $this->assertSame("FP PIN=1\tTMP=[40 chars]", Protocol::maskBiometrics("FP PIN=1\tTMP={$tmp}"));
        $this->assertSame('Content=[40 chars]', Protocol::maskBiometrics("Content={$tmp}"));
        $this->assertSame('TMP=short', Protocol::maskBiometrics('TMP=short'));
    }

    public function test_body_for_log_handles_binary_invalid_utf8_and_size_cap()
    {
        $this->assertNull(Protocol::bodyForLog(''));
        $this->assertSame('[binary 3 bytes]', Protocol::bodyForLog("a\0b"));
        $this->assertTrue(mb_check_encoding(Protocol::bodyForLog("bad \xC3\x28 utf8"), 'UTF-8'));

        $long = Protocol::bodyForLog(str_repeat('x', Protocol::LOG_BODY_CAP + 10));
        $this->assertStringEndsWith('bytes total]', $long);
        $this->assertLessThan(Protocol::LOG_BODY_CAP + 100, strlen($long));
    }

    public function test_clean_text_converts_windows_1252_and_strips_control_characters()
    {
        $this->assertSame('José', Protocol::cleanText("Jos\xE9", 50));
        $this->assertSame('Jane', Protocol::cleanText("Ja\x00ne\x07  ", 50));
        $this->assertSame('Muhammad A', Protocol::cleanText('Muhammad Atique', 10));
        $this->assertSame('محمد', Protocol::cleanText('محمد', 10));
    }

    public function test_field_removes_tabs_and_line_breaks()
    {
        $this->assertSame('a b c', Protocol::field("a\tb\r\nc"));
    }
}
