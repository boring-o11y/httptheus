<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The switches have to be thrown before the provider boots, which is why these
 * live in their own case rather than calling config() mid-test.
 */
class InstrumentationDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('httptheus.instrument.laravel_client', false);
    }

    #[Test]
    public function it_leaves_the_http_client_alone(): void
    {
        $middleware = $this->app->make(Factory::class)->getGlobalMiddleware();

        $this->assertEmpty(array_filter(
            $middleware,
            fn ($entry) => $entry instanceof RecordHttpMetrics,
        ));
    }

    #[Test]
    public function it_records_nothing(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        Http::get('https://api.example.com/v1/users');

        $this->assertNothingRecorded();
    }
}
