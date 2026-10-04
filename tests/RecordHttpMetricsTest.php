<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\Test;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use RuntimeException;

/**
 * Driven through a bare handler stack rather than Laravel's client: a mock
 * handler genuinely invokes `on_stats` for both fulfilment and rejection, so
 * these exercise the real seam instead of a stub of it.
 */
class RecordHttpMetricsTest extends TestCase
{
    #[Test]
    public function it_records_a_successful_transfer(): void
    {
        $this->client(new MockHandler([new GuzzleResponse(200)]))
            ->get('https://api.example.com/v1/users', ['transfer_time' => 0.25]);

        $this->assertSample(
            'httptheus_client_request_duration_seconds_count{host="api.example.com",method="GET",endpoint="/v1/users",status_class="2xx"} 1'
        );
    }

    #[Test]
    public function it_chains_rather_than_replaces_an_existing_on_stats_callback(): void
    {
        $seen = null;

        $this->client(new MockHandler([new GuzzleResponse(200)]))->get('https://api.example.com/v1/users', [
            'transfer_time' => 0.1,
            'on_stats' => function (TransferStats $stats) use (&$seen) {
                $seen = $stats->getResponse()?->getStatusCode();
            },
        ]);

        // The application's own callback is what populates handlerStats() for
        // the caller. Displacing it would change behaviour, not just observe it.
        $this->assertSame(200, $seen);
        $this->assertSample('status_class="2xx"} 1');
    }

    #[Test]
    public function a_transfer_passing_through_the_middleware_twice_is_recorded_once(): void
    {
        $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200)]));
        $middleware = $this->app->make(RecordHttpMetrics::class);

        $stack->push($middleware);
        $stack->push($middleware);

        (new Client(['handler' => $stack]))->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        $this->assertSample('status_class="2xx"} 1');
    }

    #[Test]
    public function it_records_a_connection_failure_and_still_propagates_it(): void
    {
        $request = new GuzzleRequest('GET', 'https://api.example.com/v1/users');
        $client = $this->client(new MockHandler([
            new ConnectException('Could not resolve host', $request),
        ]));

        $this->expectException(ConnectException::class);

        try {
            $client->get('https://api.example.com/v1/users');
        } finally {
            // Observing a failure must not change what the caller sees of it.
            $this->assertSample('status_class="error"} 1');
            $this->assertSample('httptheus_client_request_errors_total');
        }
    }

    #[Test]
    public function the_fallback_records_a_transfer_whose_handler_never_called_on_stats(): void
    {
        $stack = HandlerStack::create($this->handlerIgnoringStats(new GuzzleResponse(204)));
        $stack->push($this->app->make(RecordHttpMetrics::class));

        (new Client(['handler' => $stack]))->get('https://api.example.com/v1/users');

        $this->assertSample('status_class="2xx"} 1');
    }

    #[Test]
    public function the_fallback_stays_silent_when_on_stats_already_fired(): void
    {
        $this->client(new MockHandler([new GuzzleResponse(200)]))
            ->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        $this->assertSample('status_class="2xx"} 1');
        $this->assertStringNotContainsString('status_class="2xx"} 2', $this->render());
    }

    #[Test]
    public function the_fallback_can_be_turned_off(): void
    {
        $this->withConfig(['httptheus.instrument.promise_fallback' => false]);

        $stack = HandlerStack::create($this->handlerIgnoringStats(new GuzzleResponse(200)));
        $stack->push($this->app->make(RecordHttpMetrics::class));

        (new Client(['handler' => $stack]))->get('https://api.example.com/v1/users');

        $this->assertNothingRecorded();
    }

    #[Test]
    public function it_tracks_requests_in_flight_when_enabled(): void
    {
        $this->withConfig(['httptheus.metrics.in_flight' => true]);

        $this->client(new MockHandler([new GuzzleResponse(200)]))
            ->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        // Back to zero, not absent: the gauge is decremented on the way out.
        $this->assertSample('httptheus_client_requests_in_flight{host="api.example.com"} 0');
    }

    #[Test]
    public function the_fallback_keeps_the_status_of_an_http_error_raised_as_an_exception(): void
    {
        $stack = HandlerStack::create(fn ($request) => Create::rejectionFor(
            new ClientException('Not Found', $request, new GuzzleResponse(404)),
        ));
        $stack->push($this->app->make(RecordHttpMetrics::class));

        try {
            (new Client(['handler' => $stack]))->get('https://api.example.com/v1/users');
            $this->fail('The rejection should have propagated.');
        } catch (ClientException) {
            // A 404 reached the caller; it is not a transport failure.
        }

        $this->assertSample('status_class="4xx"} 1');
        $this->assertNoSample('httptheus_client_request_errors_total');
    }

    #[Test]
    public function a_handler_that_throws_before_returning_a_promise_still_leaves_flight(): void
    {
        $this->withConfig(['httptheus.metrics.in_flight' => true]);

        $stack = HandlerStack::create(fn () => throw new RuntimeException('Stray request.'));
        $stack->push($this->app->make(RecordHttpMetrics::class));

        try {
            (new Client(['handler' => $stack]))->get('https://api.example.com/v1/users');
            $this->fail('The exception should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('Stray request.', $e->getMessage());
        }

        $this->assertSample('httptheus_client_requests_in_flight{host="api.example.com"} 0');
        $this->assertSample('status_class="error"} 1');
    }

    #[Test]
    public function a_fulfilled_value_that_is_not_a_response_passes_through_untouched(): void
    {
        $middleware = $this->app->make(RecordHttpMetrics::class);
        $handler = $middleware(fn () => Create::promiseFor('not a response'));

        $result = $handler(new GuzzleRequest('GET', 'https://api.example.com/v1/users'), [])->wait();

        $this->assertSame('not a response', $result);
    }

    #[Test]
    public function the_in_flight_gauge_follows_the_enabled_host_labels(): void
    {
        $this->withConfig([
            'httptheus.metrics.in_flight' => true,
            'httptheus.labels.host' => false,
            'httptheus.labels.service' => true,
            'httptheus.services' => ['example' => '*.example.com'],
        ]);

        $this->client(new MockHandler([new GuzzleResponse(200)]))
            ->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        $this->assertSample('httptheus_client_requests_in_flight{service="example"} 0');
        $this->assertNoSample('host="api.example.com"');
    }

    #[Test]
    public function a_long_lived_middleware_follows_the_registry_into_the_next_scope(): void
    {
        // The HTTP client factory holds this instance for the life of an Octane
        // worker, so it must not hold the first request's registry with it.
        $client = $this->client(new MockHandler([new GuzzleResponse(200), new GuzzleResponse(200)]));
        $client->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        $this->app->forgetScopedInstances();
        $this->app->instance(CollectorRegistry::class, new CollectorRegistry($next = new InMemory, false));

        $client->get('https://api.example.com/v1/users', ['transfer_time' => 0.1]);

        $this->assertSample('status_class="2xx"} 1');
        $this->assertNotSame([], $next->collect());
    }

    private function client(MockHandler $handler): Client
    {
        $stack = HandlerStack::create($handler);
        $stack->push($this->app->make(RecordHttpMetrics::class));

        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    /**
     * A handler that resolves without ever invoking `on_stats`, the way some
     * SDK shims and stub handlers do.
     */
    private function handlerIgnoringStats(GuzzleResponse $response): callable
    {
        return fn ($request, array $options) => Create::promiseFor($response);
    }
}
