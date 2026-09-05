<?php

namespace BoringO11y\Httptheus;

use BoringO11y\Httptheus\Console\DemoTrafficCommand;
use BoringO11y\Httptheus\Console\WipeCommand;
use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use BoringO11y\Httptheus\Metrics\RegistryFactory;
use BoringO11y\Httptheus\Metrics\StorageFactory;
use BoringO11y\Httptheus\Recording\HttpMetricsRecorder;
use BoringO11y\Httptheus\Recording\LabelResolver;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HttptheusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/httptheus.php', 'httptheus');

        $this->app->singleton(StorageFactory::class);
        $this->app->singleton(LabelResolver::class);

        // Scoped rather than singleton: an adopted registry (spatie binds its
        // own as scoped) is torn down between Octane requests, and holding on
        // to it here would write metrics into an adapter nothing renders.
        $this->app->scoped(RegistryFactory::class);
        $this->app->scoped(Metrics\MetricSet::class);
        $this->app->scoped(HttpMetricsRecorder::class);
        $this->app->scoped(RecordHttpMetrics::class);
    }

    public function boot(): void
    {
        if (! config('httptheus.enabled')) {
            return;
        }

        $this->registerGlobalMiddleware();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
            $this->registerAboutCommand();
        }
    }

    private function registerGlobalMiddleware(): void
    {
        if (! config('httptheus.instrument.laravel_client')) {
            return;
        }

        // callAfterResolving rather than make(): the HTTP client should not be
        // constructed in requests that never make an outbound call.
        $this->callAfterResolving(Factory::class, function (Factory $factory): void {
            // The factory is a container singleton and globalMiddleware()
            // appends. Under Octane boot() runs again on every request against
            // a factory that survived, so a flag on the provider would not
            // help — the state that matters lives on the factory, so ask it.
            foreach ($factory->getGlobalMiddleware() as $middleware) {
                if ($middleware instanceof RecordHttpMetrics) {
                    return;
                }
            }

            $factory->globalMiddleware($this->app->make(RecordHttpMetrics::class));
        });
    }

    private function registerRoutes(): void
    {
        if (! config('httptheus.route.enabled')) {
            return;
        }

        Route::group(Httptheus::routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/metrics.php');
        });
    }

    private function registerCommands(): void
    {
        $this->commands([WipeCommand::class]);

        if (config('httptheus.demo')) {
            $this->commands([DemoTrafficCommand::class]);
        }
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/httptheus.php' => config_path('httptheus.php'),
        ], 'httptheus-config');

        $this->publishes([
            __DIR__ . '/../dashboards' => base_path('grafana/httptheus'),
        ], 'httptheus-dashboard');
    }

    /**
     * The resolved storage driver is the answer to "why is my scrape empty",
     * and `php artisan about` is where people look for it.
     */
    private function registerAboutCommand(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Httptheus', fn () => [
            'Storage' => fn () => $this->app->make(RegistryFactory::class)->isAdopted()
                ? 'adopted registry'
                : $this->app->make(StorageFactory::class)->driver(),
            'Scrape route' => fn () => config('httptheus.route.enabled')
                ? '/' . ltrim((string) config('httptheus.route.path'), '/')
                : 'disabled',
        ]);
    }
}
