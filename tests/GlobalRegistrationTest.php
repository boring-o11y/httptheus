<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use BoringO11y\Httptheus\HttptheusServiceProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class GlobalRegistrationTest extends TestCase
{
    #[Test]
    public function it_instruments_the_laravel_http_client(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        Http::get('https://api.example.com/v1/users/42');

        // Http::fake() synthesises transfer stats with a null transfer time, so
        // this is also what pins the recorder's handling of that.
        $this->assertSample(
            'httptheus_client_request_duration_seconds_count{host="api.example.com",method="GET",endpoint="/v1/users/:id",status_class="2xx"} 1'
        );
    }

    #[Test]
    public function it_instruments_pooled_requests(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        Http::pool(fn ($pool) => [
            $pool->get('https://api.example.com/v1/users'),
            $pool->get('https://api.example.com/v1/users'),
        ]);

        // Factory::newPendingRequest() hands global middleware to pooled
        // requests too; only a hand-constructed Pool misses out.
        $this->assertSample('endpoint="/v1/users",status_class="2xx"} 2');
    }

    #[Test]
    public function booting_twice_does_not_instrument_twice(): void
    {
        $factory = $this->app->make(Factory::class);

        // Under Octane boot() runs again on every request against a factory
        // that survived, and globalMiddleware() appends.
        (new HttptheusServiceProvider($this->app))->boot();
        (new HttptheusServiceProvider($this->app))->boot();

        $recorders = array_filter(
            $factory->getGlobalMiddleware(),
            fn ($middleware) => $middleware instanceof RecordHttpMetrics,
        );

        $this->assertCount(1, $recorders);
    }

    #[Test]
    public function it_records_a_faked_failure_status(): void
    {
        Http::fake(['api.example.com/*' => Http::response('nope', 503)]);

        Http::get('https://api.example.com/v1/users');

        $this->assertSample('status_class="5xx"} 1');
    }
}
