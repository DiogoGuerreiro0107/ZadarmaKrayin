<?php

namespace Webkul\Zadarma\Providers;

use Illuminate\Support\ServiceProvider;

class ZadarmaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'zadarma');
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/zadarma.php', 'zadarma');

        $this->mergeConfigFrom(__DIR__.'/../Config/core_config.php', 'core_config');
    }
}
