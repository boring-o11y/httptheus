<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Metrics\MetricSet;
use BoringO11y\Httptheus\Recording\LabelResolver;
use BoringO11y\Httptheus\Recording\HttpMetricsRecorder;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class HttpMetricsRecorderTest extends TestCase
{
    #[Test]
    public function it_records_a_successful_transfer(): void
    {
        $this->recorder()->recordStats($this->stats(new GuzzleResponse(200), 0.25));

        $this->assertSample(
            'httptheus_client_request_duration_seconds_count{host="api.example.com",method="GET",endpoint="/v1/users",status_class="2xx"} 1'
        );
        $this->assertSample(
            'httptheus_client_request_duration_seconds_sum{host="api.example.com",method="GET",endpoint="/v1/users",status_class="2xx"} 0.25'
        );
    }

    #[Test]
    public function an_observation_lands_in_the_bucket_it_names(): void
    {
        $this->recorder()->recordStats($this->stats(new GuzzleResponse(200), 0.25));

        // 0.25 is a bucket boundary, and Prometheus buckets are inclusive.
        $this->assertSample('endpoint="/v1/users",status_class="2xx",le="0.25"} 1');
        $this->assertSample('endpoint="/v1/users",status_class="2xx",le="0.1"} 0');
    }

    #[Test]
    public function it_classifies_a_server_error_by_status_class_only(): void
    {
        $this->recorder()->recordStats($this->stats(new GuzzleResponse(500), 0.1));

        $this->assertSample('status_class="5xx"} 1');

        // An HTTP error is fully described by status_class. Counting it again
        // would publish a second set of series carrying the same numbers.
        $this->assertNoSample('httptheus_client_request_errors_total');
    }

    #[Test]
    public function it_records_a_connection_failure_with_a_transport_reason(): void
    {
        // 28 is CURLE_OPERATION_TIMEDOUT, which is what Guzzle's cURL handler
        // passes through as the handler error data.
        $this->recorder()->recordStats(new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users'),
            null,
            2.0,
            28,
        ));

        $this->assertSample('status_class="error"} 1');
        $this->assertSample(
            'httptheus_client_request_errors_total{host="api.example.com",method="GET",endpoint="/v1/users",reason="timeout"} 1'
        );
    }

    #[Test]
    public function an_unrecognised_handler_error_is_classified_as_other(): void
    {
        $this->recorder()->recordStats(new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users'),
            null,
            0.5,
            'something the handler made up',
        ));

        $this->assertSample('reason="other"} 1');
    }

    #[Test]
    public function it_records_a_transfer_with_no_transfer_time(): void
    {
        // Http::fake() synthesises transfer stats with a null transfer time.
        $this->recorder()->recordStats(new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users'),
            new GuzzleResponse(200),
        ));

        $this->assertSample('status_class="2xx"} 1');
        $this->assertSample('httptheus_client_request_duration_seconds_sum{host="api.example.com",method="GET",endpoint="/v1/users",status_class="2xx"} 0');
    }

    #[Test]
    public function it_skips_an_ignored_host(): void
    {
        $this->withConfig(['httptheus.ignore_hosts' => ['*.example.com']]);

        $this->recorder()->recordStats($this->stats(new GuzzleResponse(200), 0.1));

        $this->assertNothingRecorded();
    }

    #[Test]
    public function a_recording_failure_is_reported_and_never_reaches_the_caller(): void
    {
        Exceptions::fake();

        $metrics = $this->createStub(MetricSet::class);
        $metrics->method('duration')->willThrowException(new RuntimeException('storage is down'));

        $recorder = new HttpMetricsRecorder($metrics, $this->app->make(LabelResolver::class));

        // Guzzle rethrows anything escaping on_stats as the request's own
        // failure, so this must not throw.
        $recorder->recordStats($this->stats(new GuzzleResponse(200), 0.1));

        Exceptions::assertReported(RuntimeException::class);
        $this->assertNothingRecorded();
    }

    #[Test]
    public function the_histogram_can_be_turned_off_on_its_own(): void
    {
        $this->withConfig(['httptheus.metrics.duration_histogram' => false]);

        $this->recorder()->recordStats(new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users'),
            null,
            1.0,
            6,
        ));

        $this->assertNoSample('httptheus_client_request_duration_seconds');
        $this->assertSample('reason="dns"} 1');
    }

    private function stats(GuzzleResponse $response, float $seconds): TransferStats
    {
        return new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users?role=admin'),
            $response,
            $seconds,
        );
    }
}
