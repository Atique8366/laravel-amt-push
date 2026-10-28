<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of biometric-push/laravel-amt-push. Names use config('amt-push.table_prefix').
 * A named class (not an anonymous one) so it also runs on Laravel 8.0-8.36.
 */
class CreateAmtPushTables extends Migration
{
    private function t(string $name): string
    {
        return config('amt-push.table_prefix', 'amt_push_') . $name;
    }

    private function schema()
    {
        return Schema::connection(config('amt-push.connection'));
    }

    public function up()
    {
        $schema = $this->schema();

        if (!$schema->hasTable($this->t('devices'))) {
            $schema->create($this->t('devices'), function (Blueprint $table) {
                $table->id();
                $table->string('serial_number', 64)->unique();
                $table->string('name')->nullable();
                $table->string('tenant_id', 64)->nullable()->index();
                $table->string('registration_status', 16)->default('pending');   // pending | approved | blocked
                $table->string('token', 64)->nullable()->unique();
                $table->boolean('read_only')->default(false);
                $table->boolean('process_punches')->default(true);
                $table->date('punches_from')->nullable();
                $table->boolean('capture_biometrics')->default(true);
                $table->string('timezone', 64)->nullable();

                // reported by the device
                $table->string('cloud_id', 64)->nullable();
                $table->string('terminal_type', 32)->nullable();
                $table->unsignedInteger('terminal_id')->nullable();
                $table->string('product_name', 100)->nullable();
                $table->string('firmware_version', 100)->nullable();
                $table->unsignedInteger('log_count')->nullable();       // GetGlogPosInfo
                $table->unsignedInteger('log_capacity')->nullable();
                $table->unsignedInteger('user_count')->nullable();
                $table->text('info')->nullable();                        // JSON: GetDeviceInfoAll / GetDeviceStatusAll values

                // log position: LogIDs restart after a clear → a new epoch
                $table->unsignedInteger('log_epoch')->default(0);
                $table->unsignedBigInteger('last_log_id')->nullable();
                $table->timestamp('cleared_at')->nullable();

                $table->boolean('is_online')->default(false);
                $table->string('session_id', 64)->nullable();
                $table->string('last_ip', 45)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_punch_at')->nullable();
                $table->timestamp('last_pull_at')->nullable();
                $table->timestamp('registered_at')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable($this->t('punches'))) {
            $schema->create($this->t('punches'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('serial_number', 64);
                $table->unsignedInteger('log_epoch')->default(0);
                $table->unsignedBigInteger('log_id')->nullable();
                $table->string('user_id', 32);
                $table->dateTime('punched_at');                          // device local time
                $table->smallInteger('utc_offset_minutes')->nullable();
                $table->string('action', 32)->nullable();                // FP, FACE, PWD, CD, FP+CD, ...
                $table->string('attend_stat', 32)->nullable();           // Duty On, Duty Off, In, Out, ...
                $table->string('ap_stat', 32)->nullable();
                $table->unsignedInteger('job_code')->default(0);
                $table->boolean('photo')->default(false);
                $table->mediumText('log_image')->nullable();
                $table->string('latitude', 32)->nullable();
                $table->string('longitude', 32)->nullable();
                $table->string('source', 8)->default('push');           // push (TimeLog_v2) | pull (GetGlog)
                $table->string('employee_id', 64)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('process_result', 191)->nullable();
                $table->unsignedTinyInteger('process_attempts')->default(0);
                $table->timestamps();

                $table->unique(['device_id', 'log_epoch', 'log_id'], 'amt_push_punches_log');
                $table->index(['device_id', 'processed_at'], 'amt_push_punches_pending');
                $table->index(['employee_id', 'punched_at'], 'amt_push_punches_employee');
                $table->index(['device_id', 'user_id', 'punched_at'], 'amt_push_punches_user_time');
            });
        }

        if (!$schema->hasTable($this->t('admin_logs'))) {
            $schema->create($this->t('admin_logs'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedInteger('log_epoch')->default(0);
                $table->unsignedBigInteger('log_id')->nullable();
                $table->string('admin_id', 32)->nullable();
                $table->string('user_id', 32)->nullable();
                $table->string('action', 64)->nullable();
                $table->string('stat', 16)->nullable();
                $table->dateTime('occurred_at')->nullable();
                $table->boolean('handled')->default(false);
                $table->timestamps();

                $table->index(['device_id', 'log_id'], 'amt_push_admin_logs_device');
            });
        }

        if (!$schema->hasTable($this->t('commands'))) {
            $schema->create($this->t('commands'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('request', 48);
                $table->text('payload')->nullable();                     // JSON
                $table->string('batch_id', 64)->nullable();
                $table->unsignedInteger('sequence')->default(0);
                $table->boolean('abort_batch_on_failure')->default(false);
                $table->string('status', 16)->default('pending');        // pending | sent | succeeded | failed | timed_out | cancelled
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->unsignedTinyInteger('max_attempts')->default(3);
                $table->unsignedSmallInteger('timeout_seconds')->default(15);
                $table->timestamp('available_at')->nullable();
                $table->string('employee_id', 64)->nullable();
                $table->string('user_id', 32)->nullable();
                $table->mediumText('response')->nullable();
                $table->string('error', 255)->nullable();
                $table->string('source', 16)->default('manual');
                $table->string('created_by')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['device_id', 'status'], 'amt_push_commands_device_status');
                $table->index('batch_id');
            });
        }

        if (!$schema->hasTable($this->t('device_users'))) {
            $schema->create($this->t('device_users'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->string('user_id', 32);                           // UserID on the device
                $table->string('employee_id', 64)->nullable();
                $table->string('match_status', 16)->default('unmatched');   // linked | manual | unmatched
                $table->string('name', 100)->nullable();
                $table->string('privilege', 16)->nullable();
                $table->unsignedSmallInteger('department')->nullable();
                $table->boolean('enabled')->nullable();
                $table->unsignedSmallInteger('fingers_mask')->nullable();
                $table->unsignedSmallInteger('palms_mask')->nullable();
                $table->boolean('face_enrolled')->nullable();
                $table->string('sync_status', 16)->default('synced');    // pending | queued | synced | removing | removed | failed
                $table->unsignedBigInteger('last_command_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('seen_at')->nullable();
                $table->string('last_read_id', 64)->nullable();          // user listing it was last seen in
                $table->timestamps();

                $table->unique(['device_id', 'user_id'], 'amt_push_device_users_user');
                $table->index('employee_id');
            });
        }

        if (!$schema->hasTable($this->t('biometrics'))) {
            $schema->create($this->t('biometrics'), function (Blueprint $table) {
                $table->id();
                // "e:<employee id>" for a linked person, "d:<device id>:<user id>" otherwise
                $table->string('owner_key', 100);
                $table->string('employee_id', 64)->nullable();
                $table->string('type', 8);                               // finger | face | palm | photo
                $table->unsignedTinyInteger('slot')->default(0);
                $table->boolean('duress')->default(false);
                $table->mediumText('template');
                $table->char('template_hash', 40);
                $table->unsignedBigInteger('source_device_id')->nullable();
                $table->string('source_user_id', 32)->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->timestamps();

                $table->unique(['owner_key', 'type', 'slot'], 'amt_push_biometrics_slot');
                $table->index('employee_id');
            });
        }

        if (!$schema->hasTable($this->t('device_biometrics'))) {
            $schema->create($this->t('device_biometrics'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('biometric_id');
                $table->string('user_id', 32);
                $table->char('template_hash', 40)->nullable();
                $table->string('status', 16)->default('queued');         // queued | success | on_device | failed | missing
                $table->unsignedBigInteger('last_command_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['device_id', 'biometric_id'], 'amt_push_device_biometrics_unique');
            });
        }

        if (!$schema->hasTable($this->t('messages'))) {
            $schema->create($this->t('messages'), function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('serial_number', 64)->nullable();
                $table->string('direction', 3);                          // in | out
                $table->string('kind', 48)->nullable();                  // Register, TimeLog_v2, SetUserData, ...
                $table->mediumText('payload')->nullable();               // templates / photos masked
                $table->timestamps();

                $table->index(['device_id', 'created_at'], 'amt_push_messages_device');
                $table->index('created_at');
            });
        }
    }

    public function down()
    {
        $schema = $this->schema();
        foreach (['messages', 'device_biometrics', 'biometrics', 'device_users', 'commands', 'admin_logs', 'punches', 'devices'] as $name) {
            $schema->dropIfExists($this->t($name));
        }
    }
}
