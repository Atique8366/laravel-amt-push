<?php

namespace BiometricPush\AmtPush;

use Illuminate\Support\ServiceProvider;
use BiometricPush\AmtPush\Contracts\EmployeeResolver;
use BiometricPush\AmtPush\Resolvers\ConfigEmployeeResolver;
use BiometricPush\AmtPush\Server\Kernel;
use BiometricPush\AmtPush\Server\Sessions;

class AmtPushServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/amt-push.php', 'amt-push');

        $this->app->singleton(AmtManager::class);
        $this->app->singleton(Sessions::class);
        $this->app->singleton(Kernel::class);
        $this->app->bind(EmployeeResolver::class, function ($app) {
            $class = config('amt-push.employees.resolver') ?: ConfigEmployeeResolver::class;

            return $app->make($class);
        });
    }

    public function boot()
    {
        if (config('amt-push.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/amt-push.php' => config_path('amt-push.php')], 'amt-push-config');
            $this->publishes([__DIR__ . '/../database/migrations' => database_path('migrations')], 'amt-push-migrations');

            $this->commands([
                Console\ServeCommand::class,
                Console\DevicesCommand::class,
                Console\ApproveCommand::class,
                Console\DeviceCommand::class,
                Console\ProcessCommand::class,
                Console\PruneCommand::class,
            ]);
        }
    }
}
