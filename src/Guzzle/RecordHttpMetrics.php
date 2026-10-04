<?php

namespace BoringO11y\Httptheus\Guzzle;

use BoringO11y\Httptheus\Recording\HttpMetricsRecorder;
use BoringO11y\Httptheus\Recording\TransferState;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

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

            // Resolved per transfer from the current container, never held:
            // this object ends up in the HTTP client factory's global
            // middleware and on application handler stacks, both of which
            // outlive an Octane request, while the recorder is scoped to one so
            // that it follows a registry torn down between requests.
            $recorder = Container::getInstance()->make(HttpMetricsRecorder::class);

            $state = new TransferState;
            $previous = $options['on_stats'] ?? null;

            // Chained, never replaced: PendingRequest::sendRequest() has
            // already installed its own on_stats here, and that is what
            // populates Response::handlerStats() for the caller. It runs first,
            // so the application's own behaviour cannot be delayed or displaced
            // by ours.
            $options['on_stats'] = function (TransferStats $stats) use ($previous, $state, $recorder): void {
                if (is_callable($previous)) {
                    $previous($stats);
                }

                $recorder->recordStats($stats, $state);
            };

            $recorder->enterFlight($state, $request);

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                // A handler may throw before it returns a promise — Laravel's
                // stray-request guard and beforeSending callbacks both do — and
                // the gauge was already incremented above.
                $recorder->leaveFlight($state, $request);
                $recorder->recordFallback($state, $request, null, $e);

                throw $e;
            }

            return $promise->then(
                function ($response) use ($request, $state, $recorder) {
                    $recorder->leaveFlight($state, $request);
                    $recorder->recordFallback(
                        $state,
                        $request,
                        $response instanceof ResponseInterface ? $response : null,
                        null,
                    );

                    return $response;
                },
                function ($reason) use ($request, $state, $recorder) {
                    $recorder->leaveFlight($state, $request);
                    $recorder->recordFallback(
                        $state,
                        $request,
                        self::responseOf($reason),
                        $reason,
                    );

                    // Rejections propagate untouched. Observing a failure must
                    // not change what the caller sees of it.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * An HTTP error status raised as an exception still came with a response,
     * and is not a transport failure. Guzzle 7 exposes it on RequestException
     * and 8 on ResponseException, so ask the object rather than its class.
     */
    private static function responseOf(mixed $reason): ?ResponseInterface
    {
        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;

        return $response instanceof ResponseInterface ? $response : null;
    }
}
