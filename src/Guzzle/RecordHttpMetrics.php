<?php

namespace BoringO11y\Httptheus\Guzzle;

use BoringO11y\Httptheus\Recording\HttpMetricsRecorder;
use BoringO11y\Httptheus\Recording\TransferState;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;

/**
 * The one recording seam.
 *
 * Guzzle invokes `on_stats` exactly once per transfer, after the response is
 * available but before the caller sees it, and it is the only hook that also
 * fires when the transfer produced no response at all. It carries cURL's own
 * total_time, so the duration recorded here is the transfer, not the wall clock
 * around it — which in a request pool would include time spent queued behind
 * the concurrency limit.
 *
 * Because the seam is "adjust the options, then delegate", this same object is
 * what an application pushes onto its own handler stack for an SDK that never
 * goes through Laravel's client:
 *
 *     $stack->push(app(RecordHttpMetrics::class));
 */
class RecordHttpMetrics
{
    /**
     * Marks options that have already passed through this middleware.
     */
    private const MARKER = 'httptheus_instrumented';

    public function __construct(private readonly HttpMetricsRecorder $recorder) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            // An application that pushes this onto its own stack and also
            // leaves the Laravel client instrumented would otherwise record
            // every call through both, and count all of its traffic twice.
            if ($options[self::MARKER] ?? false) {
                return $handler($request, $options);
            }

            $options[self::MARKER] = true;

            $state = new TransferState;
            $previous = $options['on_stats'] ?? null;

            // Chained, never replaced: PendingRequest::sendRequest() has
            // already installed its own on_stats here, and that is what
            // populates Response::handlerStats() for the caller. It runs first,
            // so the application's own behaviour cannot be delayed or displaced
            // by ours.
            $options['on_stats'] = function (TransferStats $stats) use ($previous, $state): void {
                if (is_callable($previous)) {
                    $previous($stats);
                }

                $state->recorded = true;
                $this->recorder->recordStats($stats);
            };

            $this->recorder->enterFlight($state, $request);

            if (! config('httptheus.instrument.promise_fallback')) {
                return $handler($request, $options)->then(
                    function ($response) use ($state, $request) {
                        $this->recorder->leaveFlight($state, $request);

                        return $response;
                    },
                    function ($reason) use ($state, $request) {
                        $this->recorder->leaveFlight($state, $request);

                        return Create::rejectionFor($reason);
                    },
                );
            }

            return $handler($request, $options)->then(
                function ($response) use ($request, $state) {
                    $this->recorder->leaveFlight($state, $request);
                    $this->recorder->recordFallback($state, $request, $response, null);

                    return $response;
                },
                function ($reason) use ($request, $state) {
                    $this->recorder->leaveFlight($state, $request);
                    $this->recorder->recordFallback($state, $request, null, $reason);

                    // Rejections propagate untouched. Observing a failure must
                    // not change what the caller sees of it.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }
}
