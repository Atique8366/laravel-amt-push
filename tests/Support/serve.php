<?php

/*
 * Starts the real amt:serve WebSocket server for the integration tests:
 *   php serve.php <vendor/autoload.php> <sqlite database file> <port>
 */

require $argv[1];

$database = $argv[2];
$port = (int) $argv[3];

$app = Orchestra\Testbench\Foundation\Application::create(null, null, [
    'extra' => ['providers' => [BiometricPush\AmtPush\AmtPushServiceProvider::class]],
]);

$config = $app['config'];
$config->set('app.timezone', 'UTC');
$config->set('database.connections.testing', [
    'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
]);
$config->set('database.default', 'testing');
$config->set('amt-push.employees.mode', 'pin');
$config->set('amt-push.server', array_merge((array) $config->get('amt-push.server'), [
    'host'         => '127.0.0.1',
    'port'         => $port,
    'tick_seconds' => 1,
    'log_file'     => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amt-push-test-' . $port . '.log',
    'pid_file'     => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amt-push-test-' . $port . '.pid',
]));

$app->make(Illuminate\Contracts\Console\Kernel::class)->call('amt:serve');
