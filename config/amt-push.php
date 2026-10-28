<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WebSocket server (php artisan amt:serve)
    |--------------------------------------------------------------------------
    | Devices connect to ws://<host>:<port>/ (or wss://). Enter that URL in
    | the device menu (Comm. → Server / Cloud). One worker process holds all
    | live connections; it handles thousands of devices.
    */
    'server' => [
        'host'            => env('AMT_PUSH_HOST', '0.0.0.0'),
        'port'            => (int) env('AMT_PUSH_PORT', 8090),
        'ssl'             => (bool) env('AMT_PUSH_SSL', false),   // wss:// (most sites terminate TLS in nginx instead)
        'ssl_cert'        => env('AMT_PUSH_SSL_CERT'),
        'ssl_key'         => env('AMT_PUSH_SSL_KEY'),
        'process_name'    => env('AMT_PUSH_PROCESS_NAME', 'amt-push-server'),
        'log_file'        => null,                                 // null = storage/logs/amt-push-server.log
        'pid_file'        => null,                                 // null = storage/framework/amt-push-server.pid

        'tick_seconds'           => 1,     // how often queued commands are sent
        'connection_timeout'     => 150,   // close sockets silent this long (devices send KeepAlive ~60 s)
        'db_heartbeat_seconds'   => 60,    // keep the long-running DB connection alive
        'max_commands_per_tick'  => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Devices
    |--------------------------------------------------------------------------
    */
    'devices' => [
        // A Register from an unknown serial number is saved as a "pending" device
        // and refused (the device retries every ~10 s) until it is approved.
        'auto_register'    => true,
        'auto_approve'     => false,
        'approved_serials' => array_filter(explode(',', (string) env('AMT_PUSH_APPROVED_SERIALS', ''))),

        // Only accept devices that send this CloudId in Register / Login (null = any).
        'cloud_id' => env('AMT_PUSH_CLOUD_ID'),

        // Timezone used for SetTime and KeepAlive ServerTime (device-local clock).
        'timezone' => env('AMT_PUSH_TIMEZONE'),   // null = config('app.timezone')

        'set_time_on_connect' => true,           // queue SetTime after each Register / Login
        'pull_on_connect'     => true,           // catch up punches after the last known LogID on Login
        'pull_every_minutes'  => 0,              // also pull every N minutes while online (0 = off)
    ],

    /*
    |--------------------------------------------------------------------------
    | Punches (TimeLog_v2 and pulled logs)
    |--------------------------------------------------------------------------
    | Every punch is stored in amt_push_punches and PunchesReceived is fired.
    | handler: class implementing BiometricPush\AmtPush\Contracts\PunchHandler, or null.
    | process: "sync"  = right after storing (inside the server process)
    |          "queue" = a ProcessPunches job per device (needs a queue worker)
    |          "manual"= only Amt::processPunches() / php artisan amt:process
    */
    'punches' => [
        'handler'            => null,
        'process'            => 'sync',
        'process_by_default' => true,
        'require_employee'   => true,
        'batch_size'         => 200,
        'max_attempts'       => 3,
        'queue'              => null,
        'connection'         => null,
        'store_log_image'    => false,   // keep <LogImage> (punch photo, base64) on the punch row
    ],

    /*
    |--------------------------------------------------------------------------
    | Employees (linking device UserIDs to your people)
    |--------------------------------------------------------------------------
    | mode: "none" | "pin" (UserID is the employee id) | "model" (look up model.pin_column)
    */
    'employees' => [
        'resolver'          => \BiometricPush\AmtPush\Resolvers\ConfigEmployeeResolver::class,
        'mode'              => 'none',
        'model'             => null,
        'key'               => 'id',
        'pin_column'        => 'id',
        'name_column'       => 'name',
        'password_column'   => null,
        'card_column'       => null,
        'department_column' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Users written to devices
    |--------------------------------------------------------------------------
    */
    'users' => [
        'default_privilege'    => 'User',   // User | Manager | Administrator
        // Let a user exist with no password / card / finger / face yet (they get templates later).
        'allow_no_certificate' => true,
        'name_max_length'      => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */
    'commands' => [
        'default_timeout_seconds' => 15,
        'max_attempts'            => 3,
        'retry_delay_seconds'     => 30,
        // EmptyTimeLog / EmptyAllData wait until every punch the device reports (GetGlogPosInfo)
        // is stored, so nothing un-uploaded is lost.
        'wait_for_uploads'        => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Biometric templates (fingerprint, face, palm, user photo)
    |--------------------------------------------------------------------------
    | pull_strategy after an enrollment on the device (AdminLog):
    |   "probe"     = ask for finger slots 0-9 and the face (proven on AMT800 hardware)
    |   "user_data" = ask GetUserData first, then only the enrolled fingers / face / palms (per spec)
    */
    'biometrics' => [
        'capture'            => true,
        'pull_after_enroll'  => true,
        'pull_strategy'      => 'probe',
        'pull_palms'         => false,   // probe palm slots 0-1 too ("probe" strategy)
        'max_photo_bytes'    => 32768,   // devices reject larger JPGs
        'enroll_actions'     => [
            'finger' => ['EnrollFP', 'BackupFP', 'EnrollUserFP', 'EnrollMgrFP', 'DeleteFP'],
            'face'   => ['EnrollFace', 'DeleteFace'],
            'photo'  => ['EnrollPhoto', 'DeletePhoto'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Message log (amt_push_messages): raw XML in / out
    |--------------------------------------------------------------------------
    */
    'message_log' => [
        'enabled'        => true,
        'log_keepalive'  => false,
        'keep_days'      => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */
    'table_prefix'   => 'amt_push_',
    'connection'     => null,
    'run_migrations' => true,
];
