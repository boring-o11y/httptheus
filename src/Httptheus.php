<?php

namespace BoringO11y\Httptheus;

use Closure;
use Illuminate\Http\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class Httptheus
{
    public const METRIC_DURATION = 'client_request_duration_seconds';

    public const METRIC_ERRORS = 'client_request_errors_total';

    public const METRIC_IN_FLIGHT = 'client_requests_in_flight';

    /**
     * The callback that authorizes access to the scrape route.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * An application-supplied replacement for the built-in path normaliser.
     *
     * @var (Closure(UriInterface, RequestInterface): ?string)|null
     */
    public static ?Closure $endpointResolver = null;

    /**
     * @param  Closure(Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Scraping reveals every host the application talks to, so an install
     * nobody has configured stays local-only rather than answering the world.
     */
    public static function check(Request $request): bool
    {
        return (static::$authUsing ?: fn () => app()->environment('local'))($request);
    }

    /**
     * Replace the built-in endpoint normalisation.
     *
     * Returning null from the callback falls back to the built-in normaliser,
     * so a resolver can special-case one API and leave the rest alone.
     *
     * @param  Closure(UriInterface, RequestInterface): ?string  $callback
     */
    public static function resolveEndpointUsing(Closure $callback): void
    {
        static::$endpointResolver = $callback;
    }

    /**
     * The unprefixed names of every metric this package emits.
     *
     * DashboardJsonTest walks the shipped dashboard against this list, so a
     * metric rename fails the suite instead of silently emptying a panel.
     *
     * @return list<string>
     */
    public static function metricNames(): array
    {
        return [
            static::METRIC_DURATION,
            static::METRIC_ERRORS,
            static::METRIC_IN_FLIGHT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function routeConfiguration(): array
    {
        return [
            'domain' => config('httptheus.route.domain'),
            'prefix' => null,
            'middleware' => array_merge(
                (array) config('httptheus.route.middleware'),
                [Http\Middleware\Authorize::class],
            ),
        ];
    }
}
