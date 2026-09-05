<?php

namespace BoringO11y\Httptheus\Tests\Integration;

use BoringO11y\Httptheus\HttptheusServiceProvider;
use BoringO11y\Httptheus\Metrics\StorageFactory;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * The predis driver against a real Redis.
 *
 * Constructing the adapter never connects, so only a live server proves the
 * client wrapper actually speaks to one — which is the whole point of offering
 * the driver to applications that never installed ext-redis.
 */
class PredisStorageTest extends TestCase
{
    private StorageFactory $factory;

    protected function setUp(): void
    {
        if (! class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('predis/predis is not installed.');
        }

        if (! getenv('HTTPTHEUS_TEST_REDIS_HOST')) {
            $this->markTestSkipped('No Redis available; set HTTPTHEUS_TEST_REDIS_HOST.');
        }

        parent::setUp();

        $this->factory = $this->app->make(StorageFactory::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->factory)) {
            $this->factory->make()->wipeStorage();
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [HttptheusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('httptheus.storage.driver', 'predis');
        $app['config']->set('httptheus.storage.redis.host', getenv('HTTPTHEUS_TEST_REDIS_HOST'));
        $app['config']->set('httptheus.storage.prefix', 'httptheus_test_' . getmypid());
        $app['config']->set('httptheus.registry', 'own');
    }

    #[Test]
    public function a_recorded_call_survives_into_a_separate_registry(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        Http::get('https://api.example.com/v1/users/42');

        // A second registry over the same storage stands in for the scrape
        // arriving in a different process from the call that was recorded.
        $scrape = (new RenderTextFormat)->render(
            (new CollectorRegistry($this->factory->make(), false))->getMetricFamilySamples()
        );

        $this->assertStringContainsString(
            'httptheus_client_request_duration_seconds_count{host="api.example.com",method="GET",endpoint="/v1/users/:id",status_class="2xx"} 1',
            $scrape,
        );
    }
}
