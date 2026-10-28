<?php

namespace BiometricPush\Adms\Tests\Feature;

use Illuminate\Support\Facades\Event;
use BiometricPush\Adms\Events\DeviceUsersUploaded;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Tests\DeviceSimulator as Sim;
use BiometricPush\Adms\Tests\TestCase;

class DeviceUsersTest extends TestCase
{
    public function test_users_uploaded_by_the_device_are_imported()
    {
        $this->approvedDevice();
        Event::fake([DeviceUsersUploaded::class]);

        $this->sim()->upload('OPERLOG', [Sim::userLine('1001', 'Jane Doe', 14, '4321', '9988'), Sim::userLine('1002', 'Ali')]);

        $jane = DeviceUser::where('pin', '1001')->first();
        $this->assertSame('Jane Doe', $jane->name);
        $this->assertSame('14', $jane->privilege);
        $this->assertSame('4321', $jane->password);
        $this->assertSame('9988', $jane->card);
        $this->assertSame('0000000100000000', $jane->tz);
        $this->assertSame('synced', $jane->sync_status);
        $this->assertSame('unmatched', $jane->match_status);
        $this->assertNull(DeviceUser::where('pin', '1002')->value('card'), 'Card=0 means no card');
        Event::assertDispatched(DeviceUsersUploaded::class, function ($e) {
            return $e->users->count() === 2;
        });
    }

    public function test_pin_mode_links_uploaded_users_automatically()
    {
        config(['adms.employees.mode' => 'pin']);
        $this->approvedDevice();

        $this->sim()->upload('USERINFO', [Sim::userLine('77', 'X')]);

        $u = DeviceUser::where('pin', '77')->first();
        $this->assertSame('77', $u->employee_id);
        $this->assertSame('linked', $u->match_status);
    }

    public function test_set_user_reads_the_device_first_and_keeps_its_values()
    {
        $this->approvedDevice();
        $sim = $this->sim();

        $read = Adms::device('SN0001')->setUser('1001', 'Jane D.');
        $this->assertSame('query_users', $read->type);
        $this->assertSame('DATA QUERY USERINFO', $read->command);

        // the device answers: uploads its users, then the command result
        $sim->poll();
        $sim->upload('USERINFO', [Sim::userLine('1001', 'Jane Doe', 14, '4321', '9988')], null);
        $sim->answer([$read->id => 1]);

        $update = Command::where('type', 'user_update')->first();
        $this->assertNotNull($update, 'write queued after the read');
        $this->assertSame("DATA UPDATE USERINFO PIN=1001\tName=Jane D.\tPri=14\tPasswd=4321\tCard=9988\tGrp=1\tTZ=0000000100000000\tVerify=0",
            $update->command, 'admin level, password, card and TZ from the device are kept');

        $sim->poll();
        $sim->answer([$update->id => 0]);
        $u = DeviceUser::where('pin', '1001')->first();
        $this->assertSame('synced', $u->sync_status);
        $this->assertSame('Jane D.', $u->name);
    }

    public function test_failed_read_writes_nothing()
    {
        $this->approvedDevice();
        $read = Adms::device('SN0001')->setUser('1001', 'Jane');
        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$read->id => -3]);

        $this->assertSame(0, Command::where('type', 'user_update')->count());
    }

    public function test_several_writes_share_one_waiting_read_and_the_last_write_wins()
    {
        $this->approvedDevice();
        $device = Adms::device('SN0001');

        $a = $device->setUser('1', 'First');
        $b = $device->setUser('2', 'Second');
        $c = $device->setUser('1', 'First renamed');

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame(['1', '2'], array_column($a->fresh()->meta['then_set_users'], 'pin'));
        $this->assertSame('First renamed', $a->fresh()->meta['then_set_users'][0]['name']);
    }

    public function test_direct_write_without_read_first_and_unchanged_users_are_skipped()
    {
        $this->approvedDevice();
        $device = Adms::device('SN0001');

        $cmd = $device->setUser('5', 'Bob', ['privilege' => 'admin', 'password' => '11', 'read_first' => false]);
        $this->assertSame("DATA UPDATE USERINFO PIN=5\tName=Bob\tPri=6\tPasswd=11\tCard=\tGrp=1\tTZ=0000000000000000\tVerify=0", $cmd->command);
        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$cmd->id => 0]);

        $this->assertNull($device->setUser('5', 'Bob', ['read_first' => false]), 'device already has exactly this user');
        $this->assertNotNull($device->setUser('5', 'Bob', ['read_first' => false, 'force' => true]));
    }

    public function test_names_are_cut_and_cleaned()
    {
        $this->approvedDevice('SN0001', ['name_max_length' => 8]);

        $cmd = Adms::device('SN0001')->setUser('5', "Very\tLong Name Here", ['read_first' => false]);

        $this->assertStringContainsString("\tName=Very Lon\t", $cmd->command);
    }

    public function test_invalid_pins_are_refused()
    {
        $this->approvedDevice();

        $this->expectException(\InvalidArgumentException::class);
        Adms::device('SN0001')->setUser('12 34', 'Bad', ['read_first' => false]);
    }

    public function test_remove_user_marks_removed_and_a_second_remove_returns_the_waiting_one()
    {
        config(['adms.commands.wait_for_uploads' => false]);
        $this->approvedDevice();
        $device = Adms::device('SN0001');
        $this->sim()->upload('USERINFO', [Sim::userLine('9', 'Gone')], null);

        $first = $device->removeUser('9');
        $second = $device->removeUser('9');
        $this->assertSame($first->id, $second->id);

        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$first->id => 0]);
        $u = $device->user('9');
        $this->assertSame('removed', $u->sync_status);
        $this->assertNotNull($u->removed_at);
        $this->assertCount(0, $device->users());
        $this->assertCount(1, $device->users(true));
    }

    public function test_user_not_found_on_delete_counts_as_removed()
    {
        config(['adms.commands.wait_for_uploads' => false]);
        $this->approvedDevice();
        $cmd = Adms::device('SN0001')->removeUser('404');
        $sim = $this->sim();
        $sim->poll();
        $sim->answer([$cmd->id => -10]);

        $this->assertSame('removed', DeviceUser::where('pin', '404')->value('sync_status'));
    }

    public function test_full_read_marks_users_deleted_on_the_device()
    {
        $this->approvedDevice();
        $sim = $this->sim();
        $sim->upload('USERINFO', [Sim::userLine('1', 'A'), Sim::userLine('2', 'B')], null);

        $read = Adms::device('SN0001')->refreshUsers();
        $sim->poll();
        $sim->upload('USERINFO', [Sim::userLine('1', 'A')], null);   // 2 was deleted on the device menu
        $sim->answer([$read->id => 1]);

        $this->assertSame('synced', DeviceUser::where('pin', '1')->value('sync_status'));
        $this->assertSame('removed', DeviceUser::where('pin', '2')->value('sync_status'));
    }

    public function test_link_rules()
    {
        $this->approvedDevice();
        $device = Adms::device('SN0001');

        $device->linkUser('1', 50);
        $this->assertSame('manual', $device->user('1')->match_status);

        $this->expectException(\InvalidArgumentException::class);
        $device->linkUser('2', 50);
    }
}
