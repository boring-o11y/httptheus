<?php

namespace BoringO11y\Httptheus\Tests\Integration;

use BoringO11y\Httptheus\HttptheusServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The headline claim, checked against the real package rather than a reading of
 * its source: httptheus writes into whatever Prometheus\CollectorRegistry the
 * container already has, and spatie's export route renders the whole registry —
 * so the integration is no code at all.
 *
 * Skipped everywhere spatie is absent, which is everywhere below PHP 8.4. The
 * CI job named `spatie` is where this actually runs.
 */
class SpatieRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(\Spatie\Prometheus\PrometheusServiceProvider::class)) {
            $this->markTestSkipped('spatie/laravel-prometheus is not installed.');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Spatie\Prometheus\PrometheusServiceProvider::class,
            HttptheusServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('prometheus.allowed_ips', []);
    }

    #[Test]
    public function our_metrics_are_exported_from_spaties_route_with_no_integration_code(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        Http::get('https://api.example.com/v1/users/42');

        $this->get('/prometheus')
            ->assertOk()
            ->assertSee('httptheus_client_request_duration_seconds_count', false)
            ->assertSee('endpoint="/v1/users/:id"', false);
    }

    #[Test]
    public function it_adopts_spaties_registry_rather_than_building_its_own(): void
    {
        $this->assertTrue(
            $this->app->make(\BoringO11y\Httptheus\Metrics\RegistryFactory::class)->isAdopted()
        );

        $this->assertSame(
            $this->app->make(\Prometheus\CollectorRegistry::class),
            $this->app->make(\BoringO11y\Httptheus\Metrics\RegistryFactory::class)->registry(),
        );
    }
}
