<?php

namespace BiometricPush\AmtPush\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use BiometricPush\AmtPush\AmtPushServiceProvider;
use BiometricPush\AmtPush\Facades\Amt;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Server\Kernel;

/**
 * SQLite in memory by default. For MySQL set AMT_TEST_DB=mysql and AMT_TEST_DB_DATABASE (+ _HOST,
 * _PORT, _USERNAME, _PASSWORD) to a THROWAWAY database: every test drops all its tables.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan($this->usesMysql() ? 'migrate:fresh' : 'migrate')->run();
        $this->defineAppTables();
    }

    protected function getPackageProviders($app)
    {
        return [AmtPushServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Amt' => Amt::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.connections.testing', $this->usesMysql() ? [
            'driver'    => 'mysql',
            'host'      => getenv('AMT_TEST_DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('AMT_TEST_DB_PORT') ?: '3306',
            'database'  => getenv('AMT_TEST_DB_DATABASE') ?: 'amt_push_package_test',
            'username'  => getenv('AMT_TEST_DB_USERNAME') ?: 'root',
            'password'  => getenv('AMT_TEST_DB_PASSWORD') ?: '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => 'InnoDB',
        ] : [
            'driver'   => 'sqlite',
            'database' => $this->sqliteDatabase(),
            'prefix'   => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('queue.default', 'sync');
    }

    protected function sqliteDatabase(): string
    {
        return ':memory:';
    }

    protected function usesMysql(): bool
    {
        return getenv('AMT_TEST_DB') === 'mysql';
    }

    /** Tables a test needs from the "host application". */
    protected function defineAppTables(): void
    {
    }

    protected function approvedDevice(string $sn = 'AMT800-0001', array $attributes = []): Device
    {
        return Amt::register($sn, $attributes)->model()->fresh();
    }

    protected function kernel(): Kernel
    {
        return app(Kernel::class);
    }

    /** A simulated terminal talking to the server kernel. */
    protected function terminal(string $sn = 'AMT800-0001'): FakeTerminal
    {
        return new FakeTerminal($this->kernel(), $sn);
    }
}
