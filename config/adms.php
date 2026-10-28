<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Device endpoints (/iclock/*)
    |--------------------------------------------------------------------------
    | The URLs the devices call. They must sit at the ROOT of the address and
    | port entered in the device's "Cloud Server / ADMS" menu, so keep the
    | prefix empty unless a proxy rewrites the path. No auth, CSRF or throttle:
    | devices are identified by serial number and must be approved.
    |
    | Set enabled=false to register them yourself: \BiometricPush\Adms\Facades\Adms::routes();
    */
    'iclock' => [
        'enabled'    => true,
        'prefix'     => '',
        'domain'     => null,
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    */
    'devices' => [
        // An unknown serial number that connects is saved as a "pending" device.
        'auto_register' => true,

        // Approve new devices automatically (otherwise: Adms::device($sn)->approve()
        // or `php artisan adms:approve SN`). Serials listed here are always approved.
        'auto_approve'     => false,
        'approved_serials' => array_filter(explode(',', (string) env('ADMS_APPROVED_SERIALS', ''))),

        // Defaults for new devices (each device row can override them).
        'timezone_offset'    => null,   // "+05:00"; null = offset of config('app.timezone')
        'delay_seconds'      => 10,     // how often the device polls for commands
        'error_delay_seconds'=> 60,     // retry delay after a network error
        'realtime'           => true,   // upload each punch immediately
        'trans_interval'     => 1,      // minutes between batch uploads
        'trans_times'        => '',     // fixed upload times, e.g. "00:00;14:05"
        'trans_flag'         => "TransData AttLog\tOpLog\tAttPhoto\tEnrollUser\tChgUser\tEnrollFP\tChgFP\tFACE\tUserPic\tBioPhoto",
        'name_max_length'    => 24,     // user names are cut to this length on the device

        // A device counts as online when it polled within max(60, 3 x delay) seconds.
        'online_window_seconds' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Punches (ATTLOG)
    |--------------------------------------------------------------------------
    | Every punch is stored in adms_punches (duplicates ignored) and the
    | PunchesReceived event is fired. Optionally a handler class turns each
    | punch into your own attendance record.
    |
    | handler: class implementing BiometricPush\Adms\Contracts\PunchHandler, or null
    | process: "sync"   = right after the upload (and leftovers on each poll)
    |          "queue"  = a ProcessPunches job per device (needs a queue worker)
    |          "manual" = only Adms::processPunches() / `php artisan adms:process`
    */
    'punches' => [
        'handler'          => null,
        'process'          => 'sync',
        'process_by_default' => true,   // value of devices.process_punches for new devices
        'require_employee' => true,     // punches of unlinked PINs are marked "unmatched", not handled
        'batch_size'       => 200,
        'max_attempts'     => 3,        // handler exceptions are retried this many times
        'queue'            => null,     // queue name for "queue" mode
        'connection'       => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Employees (linking device PINs to your people)
    |--------------------------------------------------------------------------
    | resolver: class implementing BiometricPush\Adms\Contracts\EmployeeResolver.
    | The built-in ConfigEmployeeResolver works from the settings below:
    |
    | mode: "none"  = never link automatically (link with Adms::device()->linkUser())
    |       "pin"   = the device PIN IS the employee id
    |       "model" = look the PIN up in `model`.`pin_column`
    */
    'employees' => [
        'resolver'        => \BiometricPush\Adms\Resolvers\ConfigEmployeeResolver::class,
        'mode'            => 'none',
        'model'           => null,      // e.g. App\Models\Employee::class
        'key'             => 'id',      // employee id column
        'pin_column'      => 'id',      // column whose value is the device PIN
        'name_column'     => 'name',
        'card_column'     => null,
        'password_column' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Device users and commands
    |--------------------------------------------------------------------------
    */
    'users' => [
        // Before writing users, read the device's current user list first, so
        // admin levels, passwords and cards that exist only on the device are kept.
        'read_first' => true,
    ],

    'commands' => [
        'max_per_poll'           => 50,
        'max_bytes_per_poll'     => 1048576,
        'answer_timeout_seconds' => 300,
        'max_attempts'           => 3,

        // Deleting a user / clearing punches waits until every punch the device
        // reports is stored, so nothing un-uploaded is lost.
        'wait_for_uploads' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Biometric templates (fingerprint, face, palm, user photo)
    |--------------------------------------------------------------------------
    */
    'biometrics' => [
        'capture'          => true,     // store templates devices upload
        'read_after_enroll'=> true,     // query a person's templates after an enrollment on the device
        'max_photo_base64' => 500000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request log (adms_request_logs)
    |--------------------------------------------------------------------------
    */
    'request_log' => [
        'enabled'         => true,
        'skip_idle_polls' => true,      // don't log polls with nothing to report
        'keep_days'       => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'table_prefix' => 'adms_',
    'connection'   => null,

    'run_migrations' => true,           // false after `php artisan vendor:publish --tag=adms-migrations`
];
