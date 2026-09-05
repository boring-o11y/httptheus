<?php

namespace BoringO11y\Httptheus\Recording;

use BoringO11y\Httptheus\Metrics\MetricSet;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class HttpMetricsRecorder
{
    public function __construct(
        private readonly MetricSet $metrics,
        private readonly LabelResolver $labels,
    ) {}

    public function recordStats(TransferStats $stats): void
    {
        try {
            $response = $stats->getResponse();

            $this->observe(
                $stats->getRequest(),
                $response,
                // Http::fake() synthesises transfer stats with a null transfer
                // time. Record the call rather than fabricating a duration: a
                // zero is visibly wrong on a dashboard, a TypeError here would
                // be swallowed below and lose the observation entirely.
                (float) ($stats->getTransferTime() ?? 0.0),
                $response === null,
                $stats->getHandlerErrorData(),
            );
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    /**
     * Record a transfer whose handler never invoked `on_stats`.
     */
    public function recordFallback(
        TransferState $state,
        RequestInterface $request,
        ?ResponseInterface $response,
        mixed $reason,
    ): void {
        if ($state->recorded) {
            return;
        }

        try {
            $state->recorded = true;

            $this->observe($request, $response, $state->elapsed(), $response === null, $reason);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    public function enterFlight(TransferState $state, RequestInterface $request): void
    {
        if (! config('httptheus.metrics.in_flight')) {
            return;
        }

        try {
            $host = $request->getUri()->getHost();

            if ($this->labels->ignoresHost($host)) {
                return;
            }

            $state->inFlight = true;
            $this->metrics->inFlight()->inc([$host]);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    public function leaveFlight(TransferState $state, RequestInterface $request): void
    {
        if (! $state->inFlight) {
            return;
        }

        try {
            $state->inFlight = false;
            $this->metrics->inFlight()->dec([$request->getUri()->getHost()]);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    private function observe(
        RequestInterface $request,
        ?ResponseInterface $response,
        float $seconds,
        bool $failed,
        mixed $handlerErrorData,
    ): void {
        if ($this->labels->ignoresHost($request->getUri()->getHost())) {
            return;
        }

        $status = $response?->getStatusCode();
        $values = $this->labels->values($request, $status, $failed);

        if (config('httptheus.metrics.duration_histogram')) {
            $this->metrics->duration()->observe($seconds, $values);
        }

        // Only transport failures reach the counter. An HTTP error status is
        // already fully described by the histogram's status_class, and counting
        // it twice would buy a second set of series carrying the same numbers.
        if ($failed && config('httptheus.metrics.errors_total')) {
            $this->metrics->errors()->inc(
                $this->labels->errorValues($values, $this->labels->reason($handlerErrorData)),
            );
        }
    }

    /**
     * Instrumentation must never break the call it observes. Guzzle's cURL
     * handler rethrows anything that escapes `on_stats` as the request's own
     * failure, so a bug in here would surface to the application as the
     * outbound call itself having failed.
     */
    private function report(Throwable $e): void
    {
        report($e);
    }
}
