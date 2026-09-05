<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\Test;

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
        return fn ($request, array $options) => \GuzzleHttp\Promise\Create::promiseFor($response);
    }
}
