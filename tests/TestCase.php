<?php

namespace BiometricPush\Adms\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use BiometricPush\Adms\AdmsServiceProvider;
use BiometricPush\Adms\Facades\Adms;
use BiometricPush\Adms\Models\Device;

/**
 * SQLite in memory by default. To run against MySQL (a THROWAWAY database: every test
 * drops all its tables) set ADMS_TEST_DB=mysql and ADMS_TEST_DB_DATABASE / _HOST / _PORT /
 * _USERNAME / _PASSWORD.
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
        return [AdmsServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Adms' => Adms::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        if ($this->usesMysql()) {
            $app['config']->set('database.connections.testing', [
                'driver'    => 'mysql',
                'host'      => getenv('ADMS_TEST_DB_HOST') ?: '127.0.0.1',
                'port'      => getenv('ADMS_TEST_DB_PORT') ?: '3306',
                'database'  => getenv('ADMS_TEST_DB_DATABASE') ?: 'adms_package_test',
                'username'  => getenv('ADMS_TEST_DB_USERNAME') ?: 'root',
                'password'  => getenv('ADMS_TEST_DB_PASSWORD') ?: '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => 'InnoDB',
            ]);
        } else {
            $app['config']->set('database.connections.testing', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
                'foreign_key_constraints' => true,
            ]);
        }
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('queue.default', 'sync');
    }

    protected function usesMysql(): bool
    {
        return getenv('ADMS_TEST_DB') === 'mysql';
    }

    /** Tables a test needs from the "host application" (none by default). */
    protected function defineAppTables(): void
    {
    }

    protected function approvedDevice(string $sn = 'SN0001', array $attributes = []): Device
    {
        return Adms::register($sn, $attributes)->model()->fresh();
    }

    /** A simulated device talking to the app over HTTP. */
    protected function sim(string $sn = 'SN0001'): DeviceSimulator
    {
        return new DeviceSimulator($this, $sn);
    }
}
