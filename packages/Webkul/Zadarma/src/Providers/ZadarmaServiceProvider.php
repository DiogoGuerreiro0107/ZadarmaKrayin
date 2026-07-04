<?php

namespace Webkul\Zadarma\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Webkul\Core\ViewRenderEventManager;
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

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'zadarma');

        $this->loadRoutesFrom(__DIR__.'/../Routes/admin-routes.php');

        $this->registerCallButtonHooks();

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(SyncCallsCommand::class)
                ->everyTenMinutes()
                ->when(fn () => config('zadarma.sync_mode') === 'polling');
        });

        // The public webhook route only exists at all when explicitly running
        // in webhook mode — never registered based on a DB toggle, to avoid
        // accidentally exposing it on an installation that is only reachable
        // locally.
        if (config('zadarma.sync_mode') === 'webhook') {
            RateLimiter::for('zadarma-webhook', fn () => Limit::perMinute(60));

            $this->loadRoutesFrom(__DIR__.'/../Routes/routes.php');
        }
    }

    /**
     * Inject the call button next to a Person's phone numbers on the Lead
     * view (via the attached person) and on the Person's own view page,
     * using Krayin's view_render_event hook points instead of overriding
     * either core Blade view.
     */
    protected function registerCallButtonHooks(): void
    {
        $addCallButton = function (ViewRenderEventManager $manager) {
            $manager->addTemplate('zadarma::components.call-button');
        };

        Event::listen('admin.leads.view.person.contact_numbers.after', $addCallButton);

        Event::listen('admin.contacts.persons.view.attributes.form_controls.attributes_view.after', $addCallButton);
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
