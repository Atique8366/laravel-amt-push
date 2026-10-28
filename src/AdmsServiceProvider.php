<?php

namespace BiometricPush\Adms;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use BiometricPush\Adms\Contracts\EmployeeResolver;
use BiometricPush\Adms\Resolvers\ConfigEmployeeResolver;

class AdmsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/adms.php', 'adms');

        $this->app->singleton(AdmsManager::class);
        $this->app->bind(EmployeeResolver::class, function ($app) {
            $class = config('adms.employees.resolver') ?: ConfigEmployeeResolver::class;

            return $app->make($class);
        });
    }

    public function boot()
    {
        if (config('adms.iclock.enabled', true)) {
            Route::group(array_filter([
                'prefix'     => config('adms.iclock.prefix', ''),
                'domain'     => config('adms.iclock.domain'),
                'middleware' => config('adms.iclock.middleware', []),
            ]), function () {
                $this->app->make(AdmsManager::class)->routes();
            });
        }

        if (config('adms.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/adms.php' => config_path('adms.php')], 'adms-config');
            $this->publishes([__DIR__ . '/../database/migrations' => database_path('migrations')], 'adms-migrations');

            $this->commands([
                Console\DevicesCommand::class,
                Console\ApproveCommand::class,
                Console\DeviceCommand::class,
                Console\ProcessCommand::class,
                Console\PruneCommand::class,
            ]);
        }
    }
}
