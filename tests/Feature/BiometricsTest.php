<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\Adms\Events\BiometricsCaptured;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\BiometricTemplate;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\DeviceTemplate;
use BiometricPush\Adms\Tests\DeviceSimulator as Sim;
use BiometricPush\Adms\Tests\TestCase;

class BiometricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->approvedDevice();
        $this->sim()->uploadOptions();   // FP 10, face 7, photos
    }

    public function test_templates_of_a_linked_user_are_stored_per_employee()
    {
        Event::fake([BiometricsCaptured::class]);
        Adms::device('SN0001')->linkUser('191', 103);

        $this->sim()->upload('OPERLOG', [
            Sim::fpLine('191', 6, Sim::template('a')), Sim::faceLine('191', 0, Sim::template('b')),
        ]);

        $templates = Adms::templates(103);
        $this->assertCount(2, $templates);
        $this->assertSame(['fingerprint', 'face'], $templates->map(function ($t) {
            return BiometricTemplate::typeName($t->bio_type);
        })->all());
        $this->assertSame('e:103', $templates[0]->owner_key);
        $this->assertSame(2, DeviceTemplate::where('status', 'on_device')->count());
        Event::assertDispatched(BiometricsCaptured::class);
    }

    public function test_unlinked_templates_follow_the_pin_when_it_is_linked_later()
    {
        $this->sim()->upload('FINGERTMP', ["PIN=55\tFID=1\tSize=120\tValid=1\tTMP=" . Sim::template('x')]);
        $this->assertSame('d:1:55', BiometricTemplate::value('owner_key'));

        Adms::device('SN0001')->linkUser('55', 9);

        $this->assertSame('e:9', BiometricTemplate::value('owner_key'));
        $this->assertSame('9', BiometricTemplate::value('employee_id'));
    }

    public function test_same_template_again_is_unchanged_and_a_new_one_updates()
    {
        Adms::device('SN0001')->linkUser('1', 1);
        $sim = $this->sim();

        $sim->upload('OPERLOG', [Sim::fpLine('1', 0, Sim::template('v1'))]);
        $sim->upload('OPERLOG', [Sim::fpLine('1', 0, Sim::template('v1'))]);
        $this->assertSame(1, BiometricTemplate::count());

        $sim->upload('OPERLOG', [Sim::fpLine('1', 0, Sim::template('v2'))]);
        $this->assertSame(1, BiometricTemplate::count());
        $this->assertSame(sha1(Sim::template('v2')), BiometricTemplate::value('template_hash'));
    }

    public function test_user_photo_is_stored_and_returned()
    {
        Adms::device('SN0001')->linkUser('191', 103);

        $this->sim()->upload('USERPIC', ["USER PIN=191\tFileName=191.jpg\tSize=120\tContent=" . Sim::template('photo')]);

        $this->assertSame(Sim::template('photo'), Adms::photo(103));
    }

    public function test_enrollment_on_the_device_reads_the_templates_back()
    {
        $this->sim()->upload('OPERLOG', ["OPLOG 6\t0\t2026-10-27 09:00:00\t191\t1\t0\t0", "OPLOG 4\t0\t2026-10-27 09:00:00\t0\t0\t0\t0"]);

        $this->assertSame(['DATA QUERY BIODATA Type=1' . "\tPIN=191", 'DATA QUERY BIODATA Type=2' . "\tPIN=191"],
            Command::where('type', 'bio_query')->orderBy('id')->pluck('command')->all());

        $this->sim()->upload('OPERLOG', ["OPLOG 6\t0\t2026-10-27 09:00:01\t191\t1\t0\t0"]);
        $this->assertSame(2, Command::where('type', 'bio_query')->count(), 'waiting queries are not repeated');
    }

    public function test_capture_can_be_switched_off_per_device()
    {
        Adms::device('SN0001')->update(['capture_biometrics' => false])->linkUser('1', 1);

        $this->sim()->upload('OPERLOG', [Sim::fpLine('1', 0, Sim::template('a'))]);

        $this->assertSame(0, BiometricTemplate::count());
    }

    public function test_push_templates_sends_only_compatible_and_changed_ones()
    {
        Adms::device('SN0001')->linkUser('1', 7);
        $this->sim()->upload('OPERLOG', [Sim::fpLine('1', 0, Sim::template('fp')), Sim::faceLine('1', 0, Sim::template('face'))]);

        $other = $this->approvedDevice('SN0002');
        $this->sim('SN0002')->uploadOptions(['FaceVersion' => '0', 'MultiBioVersion' => '0:10.0:0:0:0:0:0:0:0:0',
            'MultiBioDataSupport' => '0:1:0:0:0:0:0:0:0:0']);
        $client = Adms::device('SN0002');

        $this->assertSame(1, $client->pushTemplates([7])['no_user'], 'user must be on the device first');

        $client->linkUser('1', 7);
        $counts = $client->pushTemplates([7]);
        $this->assertSame(1, $counts['queued']);
        $this->assertSame(1, $counts['incompatible'], 'face algorithm not on SN0002');
        $this->assertSame(1, $client->pushTemplates([7])['already_queued']);

        $sim = $this->sim('SN0002');
        $sim->poll();
        $this->assertStringStartsWith("DATA UPDATE FINGERTMP PIN=1\tFID=0", reset($sim->received));
        $sim->answerAll();
        $this->assertSame('success', DeviceTemplate::where('device_id', $other->id)->value('status'));
        $this->assertSame(1, $client->pushTemplates([7])['already_on_device']);
    }
}
