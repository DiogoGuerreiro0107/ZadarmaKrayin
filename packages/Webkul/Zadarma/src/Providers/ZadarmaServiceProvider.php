<?php

namespace Webkul\Zadarma\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Webkul\Zadarma\Console\Commands\SyncCallsCommand;

class ZadarmaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'zadarma');

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SyncCallsCommand::class)
                ->everyTenMinutes()
                ->when(fn () => config('zadarma.sync_mode') === 'polling');
        });
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/zadarma.php', 'zadarma');

        $this->mergeConfigFrom(__DIR__.'/../Config/core_config.php', 'core_config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCallsCommand::class,
            ]);
        }
    }
}
