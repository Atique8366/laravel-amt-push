<?php

namespace BiometricPush\Adms\Tests\Unit;

use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Services\BiometricService;
use BiometricPush\Adms\Tests\TestCase;

class BiometricTemplateTest extends TestCase
{
    /** @var BiometricService */
    private $bio;

    /** @var Device */
    private $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bio = app(BiometricService::class);
        $this->device = new Device(['fp_algorithm' => '10.0', 'face_algorithm' => '7']);
    }

    public function test_fingerprint_line()
    {
        $t = $this->bio->parseTemplateLine($this->device, "FP PIN=645\tFID=5\tSize=8\tValid=1\tTMP=QUJDREVGR0g=");

        $this->assertSame('645', $t['pin']);
        $this->assertSame(BiometricTemplate::TYPE_FINGERPRINT, $t['bio_type']);
        $this->assertSame(5, $t['bio_no']);
        $this->assertSame('10', $t['major_ver']);
        $this->assertFalse($t['duress']);
        $this->assertSame('FINGERTMP', $t['source_format']);
    }

    public function test_duress_fingerprint_and_disabled_template()
    {
        $this->assertTrue($this->bio->parseTemplateLine($this->device, "FP PIN=1\tFID=0\tValid=3\tTMP=QUJD")['duress']);
        $this->assertFalse($this->bio->parseTemplateLine($this->device, "FP PIN=1\tFID=0\tValid=0\tTMP=QUJD"));
    }

    public function test_face_line_uses_fid_as_index()
    {
        $t = $this->bio->parseTemplateLine($this->device, "FACE PIN=191\tFID=11\tSIZE=4\tVALID=1\tTMP=QUJD");

        $this->assertSame(BiometricTemplate::TYPE_FACE, $t['bio_type']);
        $this->assertSame(11, $t['bio_index']);
        $this->assertSame('7', $t['major_ver']);
    }

    public function test_biodata_line()
    {
        $t = $this->bio->parseTemplateLine($this->device,
            "BIODATA Pin=1\tNo=0\tIndex=2\tValid=1\tDuress=1\tType=9\tMajorVer=39\tMinorVer=1\tFormat=0\tTmp=QUJD");

        $this->assertSame(9, $t['bio_type']);
        $this->assertSame(2, $t['bio_index']);
        $this->assertSame('39', $t['major_ver']);
        $this->assertSame('1', $t['minor_ver']);
        $this->assertTrue($t['duress']);
    }

    public function test_userpic_in_both_forms()
    {
        $a = $this->bio->parseTemplateLine($this->device, "USERPIC PIN=191\tFileName=191.jpg\tSize=4\tContent=QUJD");
        $b = $this->bio->parseTemplateLine($this->device, "USER PIN=191\tFileName=191.jpg\tSize=4\tContent=QUJD", 'USERPIC');

        $this->assertSame(BiometricTemplate::TYPE_PHOTO, $a['bio_type']);
        $this->assertSame($a, $b);
    }

    public function test_table_kind_without_prefix()
    {
        $t = $this->bio->parseTemplateLine($this->device, "PIN=3\tFID=1\tValid=1\tTMP=QUJD", 'FINGERTMP');

        $this->assertSame(BiometricTemplate::TYPE_FINGERPRINT, $t['bio_type']);
    }

    public function test_not_a_template_line_and_unusable_lines()
    {
        $this->assertNull($this->bio->parseTemplateLine($this->device, "USER PIN=1\tName=A"));
        $this->assertNull($this->bio->parseTemplateLine($this->device, "OPLOG 6\t0\t2026-10-27 09:00:00\t1\t0"));
        $this->assertFalse($this->bio->parseTemplateLine($this->device, "FP PIN=1\tFID=0\tTMP=not base64!"));
        $this->assertFalse($this->bio->parseTemplateLine(new Device(), "FP PIN=1\tFID=0\tTMP=QUJD"));   // algorithm unknown
    }

    public function test_photo_over_size_limit_is_skipped()
    {
        config(['adms.biometrics.max_photo_base64' => 8]);

        $this->assertFalse($this->bio->parseTemplateLine($this->device, "USERPIC PIN=1\tContent=QUJDREVGR0hJ"));
    }

    public function test_update_commands_per_type_and_algorithm()
    {
        $fp = new BiometricTemplate(['bio_type' => 1, 'bio_no' => 5, 'bio_index' => 0, 'major_ver' => '10', 'template' => 'QUJD', 'duress' => false, 'format' => 0]);
        $this->assertSame("DATA UPDATE FINGERTMP PIN=9\tFID=5\tSize=4\tValid=1\tTMP=QUJD", $this->bio->buildUpdateCommand($fp, '9'));

        $face = new BiometricTemplate(['bio_type' => 2, 'bio_no' => 0, 'bio_index' => 3, 'major_ver' => '7', 'template' => 'QUJD', 'format' => 0]);
        $this->assertSame("DATA UPDATE FACE PIN=9\tFID=3\tValid=1\tSize=4\tTMP=QUJD", $this->bio->buildUpdateCommand($face, '9'));

        $fp12 = new BiometricTemplate(['bio_type' => 1, 'bio_no' => 1, 'bio_index' => 0, 'major_ver' => '12', 'minor_ver' => '0', 'template' => 'QUJD', 'duress' => true, 'format' => 0]);
        $this->assertSame("DATA UPDATE BIODATA Pin=9\tNo=1\tIndex=0\tValid=1\tDuress=1\tType=1\tMajorVer=12\tMinorVer=0\tFormat=0\tTmp=QUJD",
            $this->bio->buildUpdateCommand($fp12, '9'));

        $photo = new BiometricTemplate(['bio_type' => 0, 'major_ver' => '0', 'template' => 'QUJD']);
        $this->assertSame("DATA UPDATE USERPIC PIN=9\tSize=4\tContent=QUJD", $this->bio->buildUpdateCommand($photo, '9'));
    }

    public function test_device_capabilities()
    {
        $plain = new Device(['fp_algorithm' => '10']);
        $this->assertFalse($this->bio->supportsBioData($plain));
        $this->assertSame(['DATA QUERY FINGERTMP PIN=7'], $this->bio->queryCommands($plain, '7'));
        $this->assertNull($this->bio->deviceBioVersion($plain, BiometricTemplate::TYPE_PHOTO));

        $multi = new Device(['options' => "MultiBioDataSupport=0:1:1:0:0:0:0:0:1:1,MultiBioVersion=0:12.0:39.1:0:0:0:0:0:8.0:39.1,PhotoFunOn=1"]);
        $this->assertTrue($this->bio->supportsBioData($multi));
        $this->assertSame('39.1', $this->bio->deviceBioVersion($multi, 9));
        $this->assertSame('0', $this->bio->deviceBioVersion($multi, BiometricTemplate::TYPE_PHOTO));
        $this->assertSame([
            "DATA QUERY BIODATA Type=1\tPIN=7", "DATA QUERY BIODATA Type=2\tPIN=7",
            "DATA QUERY BIODATA Type=8\tPIN=7", "DATA QUERY BIODATA Type=9\tPIN=7",
        ], $this->bio->queryCommands($multi, '7'));
    }
}
