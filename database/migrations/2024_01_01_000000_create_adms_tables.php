<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of biometric-push/laravel-adms. Names use config('adms.table_prefix').
 * A named class (not an anonymous one) so it also runs on Laravel 8.0-8.36.
 */
class CreateAdmsTables extends Migration
{
    private function t(string $name): string
    {
        return config('adms.table_prefix', 'adms_') . $name;
    }

    private function schema()
    {
        return Schema::connection(config('adms.connection'));
    }

    public function up()
    {
        $schema = $this->schema();

        if (!$schema->hasTable($this->t('devices'))) {
            $schema->create($this->t('devices'), function (Blueprint $table) {
                $table->id();
                $table->string('serial_number', 64)->unique();
                $table->string('name')->nullable();
                $table->string('tenant_id', 64)->nullable()->index();      // your company / branch / owner
                $table->string('registration_status', 16)->default('pending');   // pending | approved | blocked
                $table->boolean('push_enabled')->default(true);
                $table->boolean('read_only')->default(false);             // never write to this device
                $table->boolean('process_punches')->default(true);
                $table->date('punches_from')->nullable();                 // older punches are not handled
                $table->boolean('capture_biometrics')->default(true);

                // upload positions
                $table->string('attlog_stamp', 32)->default('0');
                $table->string('operlog_stamp', 32)->default('0');
                $table->string('attphoto_stamp', 32)->default('0');
                $table->string('errorlog_stamp', 32)->default('0');

                // push settings sent in the handshake
                $table->string('trans_flag')->nullable();
                $table->unsignedSmallInteger('delay_seconds')->default(10);
                $table->unsignedSmallInteger('error_delay_seconds')->default(60);
                $table->boolean('realtime')->default(true);
                $table->unsignedSmallInteger('trans_interval')->default(1);
                $table->string('trans_times', 100)->nullable();
                $table->string('timezone_offset', 8)->nullable();
                $table->unsignedTinyInteger('name_max_length')->default(24);

                // reported by the device
                $table->string('model')->nullable();
                $table->string('firmware_version', 100)->nullable();
                $table->string('push_version', 20)->nullable();
                $table->string('language', 10)->nullable();
                $table->string('device_ip', 45)->nullable();
                $table->unsignedInteger('user_count')->nullable();
                $table->unsignedInteger('fp_count')->nullable();
                $table->unsignedInteger('face_count')->nullable();
                $table->unsignedInteger('attlog_count')->nullable();
                $table->string('fp_algorithm', 16)->nullable();
                $table->string('face_algorithm', 16)->nullable();
                $table->boolean('mask_detection')->default(false);
                $table->boolean('temperature_detection')->default(false);
                $table->longText('options')->nullable();                  // last raw capability upload

                $table->text('meta')->nullable();                         // JSON, free for your app
                $table->string('last_ip', 45)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_attlog_at')->nullable();
                $table->timestamp('last_log_prune_at')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable($this->t('punches'))) {
            $schema->create($this->t('punches'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('serial_number', 64);
                $table->string('pin', 32);
                $table->dateTime('punched_at');                           // device local time
                $table->string('punch_status', 8)->nullable();            // 0 in, 1 out, 2/3 break, 4/5 OT, 255 none
                $table->string('verify_mode', 8)->nullable();             // 1 finger, 15 face, 3 password, 4 card ...
                $table->string('work_code', 32)->nullable();
                $table->string('mask_flag', 8)->nullable();
                $table->string('temperature', 16)->nullable();
                $table->string('raw_line', 500)->nullable();
                $table->string('employee_id', 64)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('process_result', 191)->nullable();
                $table->unsignedTinyInteger('process_attempts')->default(0);
                $table->timestamps();

                $table->unique(['serial_number', 'pin', 'punched_at'], 'adms_punches_unique');
                $table->index(['device_id', 'processed_at'], 'adms_punches_device_processed');
                $table->index(['employee_id', 'punched_at'], 'adms_punches_employee');
            });
        }

        if (!$schema->hasTable($this->t('device_users'))) {
            $schema->create($this->t('device_users'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('pin', 32);
                $table->string('employee_id', 64)->nullable();
                $table->string('match_status', 16)->default('unmatched');   // linked | manual | unmatched
                $table->string('name', 100)->nullable();
                $table->string('privilege', 8)->nullable();               // 0 user, 2 enroller, 6 admin, 14 super admin
                $table->string('password', 32)->nullable();
                $table->string('card', 32)->nullable();
                $table->string('tz', 16)->nullable();
                $table->string('sync_status', 16)->default('synced');     // pending | queued | synced | removing | removed | failed
                $table->string('sync_hash', 40)->nullable();
                $table->unsignedBigInteger('last_command_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('seen_at')->nullable();
                $table->unsignedBigInteger('last_read_id')->nullable();   // full user read (command) it was last seen in
                $table->timestamps();

                $table->unique(['device_id', 'pin'], 'adms_device_users_pin');
                $table->index('employee_id');
            });
        }

        if (!$schema->hasTable($this->t('commands'))) {
            $schema->create($this->t('commands'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('type', 32);
                $table->mediumText('command');
                $table->unsignedBigInteger('device_user_id')->nullable();
                $table->string('employee_id', 64)->nullable();
                $table->text('meta')->nullable();
                $table->string('status', 16)->default('queued');          // queued | sent | success | failed | expired | cancelled
                $table->integer('return_code')->nullable();
                $table->text('response')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->string('source', 16)->default('manual');
                $table->string('created_by')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'status'], 'adms_commands_device_status');
            });
        }

        if (!$schema->hasTable($this->t('request_logs'))) {
            $schema->create($this->t('request_logs'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('serial_number', 64)->nullable();
                $table->string('method', 10);
                $table->string('path', 191);
                $table->text('query_string')->nullable();
                $table->string('table_name', 32)->nullable();
                $table->mediumText('body')->nullable();                   // templates masked, capped at 64 KB
                $table->unsignedInteger('body_size')->default(0);
                $table->unsignedSmallInteger('response_code')->nullable();
                $table->text('response_body')->nullable();
                $table->unsignedInteger('records_parsed')->default(0);
                $table->unsignedInteger('records_saved')->default(0);
                $table->text('error')->nullable();
                $table->string('ip', 45)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'created_at'], 'adms_request_logs_device');
                $table->index('created_at');
            });
        }

        if (!$schema->hasTable($this->t('biometric_templates'))) {
            $schema->create($this->t('biometric_templates'), function (Blueprint $table) {
                $table->id();
                // "e:<employee id>" for a linked person, "d:<device id>:<pin>" for an unlinked device user
                $table->string('owner_key', 100);
                $table->string('employee_id', 64)->nullable();
                $table->unsignedTinyInteger('bio_type');                  // 0 photo, 1 finger, 2 face, 8 palm, 9 visible face
                $table->unsignedSmallInteger('bio_no')->default(0);
                $table->unsignedSmallInteger('bio_index')->default(0);
                $table->string('major_ver', 16);
                $table->string('minor_ver', 16)->nullable();
                $table->unsignedTinyInteger('format')->default(0);
                $table->boolean('duress')->default(false);
                $table->unsignedInteger('size')->default(0);
                $table->mediumText('template');
                $table->char('template_hash', 40);
                $table->string('source_format', 16);
                $table->unsignedBigInteger('source_device_id')->nullable();
                $table->string('source_pin', 32)->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->timestamps();

                $table->unique(['owner_key', 'bio_type', 'bio_no', 'bio_index', 'major_ver'], 'adms_bio_templates_unique');
                $table->index('employee_id');
            });
        }

        if (!$schema->hasTable($this->t('device_templates'))) {
            $schema->create($this->t('device_templates'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('template_id');
                $table->string('pin', 32);
                $table->char('template_hash', 40)->nullable();
                $table->string('status', 16)->default('queued');          // queued | success | on_device | failed | missing
                $table->unsignedBigInteger('last_command_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['device_id', 'template_id'], 'adms_device_templates_unique');
            });
        }
    }

    public function down()
    {
        $schema = $this->schema();
        foreach (['device_templates', 'biometric_templates', 'request_logs', 'commands', 'device_users', 'punches', 'devices'] as $name) {
            $schema->dropIfExists($this->t($name));
        }
    }
}
