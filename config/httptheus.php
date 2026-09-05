<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The master switch. When false httptheus registers nothing at all: no
    | global middleware, no scrape route, no commands.
    |
    */

    'enabled' => env('HTTPTHEUS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Metric Namespace
    |--------------------------------------------------------------------------
    |
    | Prefixed to every metric name, so the default emits
    | `httptheus_client_request_duration_seconds`. Prometheus rejects anything
    | that does not match /^[a-zA-Z_:][a-zA-Z0-9_:]*$/.
    |
    */

    'namespace' => env('HTTPTHEUS_NAMESPACE', 'httptheus'),

    /*
    |--------------------------------------------------------------------------
    | Instrumentation
    |--------------------------------------------------------------------------
    |
    | `laravel_client` installs the recording middleware on the HTTP client
    | factory, covering Http::get(), Http::pool() and everything else that goes
    | through the facade. Turn it off and push
    | BoringO11y\Httptheus\Guzzle\RecordHttpMetrics onto your own handler stacks
    | by hand.
    |
    | `promise_fallback` records a transfer whose handler never invoked
    | `on_stats` at all — some SDK shims and some Http::fake() stub shapes never
    | do. Its timings are wall clock rather than transfer time, which in a
    | request pool includes time spent queued behind the concurrency limit, so
    | it is a correctness net and not a measurement strategy.
    |
    */

    'instrument' => [
        'laravel_client' => env('HTTPTHEUS_INSTRUMENT_LARAVEL', true),
        'promise_fallback' => env('HTTPTHEUS_PROMISE_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | There is deliberately no requests_total counter: it would carry exactly
    | the numbers the histogram already publishes as `_count`. Throughput is
    | rate(httptheus_client_request_duration_seconds_count[5m]).
    |
    | `in_flight` is off because it increments on entry and decrements on exit.
    | A fatal error between the two leaks a permanent +1 into shared storage,
    | and under APCu or Redis nothing but `httptheus:wipe` will clear it.
    |
    */

    'metrics' => [
        'duration_histogram' => true,
        'errors_total' => true,
        'in_flight' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Histogram Buckets
    |--------------------------------------------------------------------------
    |
    | Seconds, strictly increasing. Every bucket is another time series per
    | label combination, so add sparingly.
    |
    | These run further out than the Prometheus client's own defaults, which
    | stop at 10 seconds. Laravel's HTTP client times out at 30 by default, and
    | without a bucket past 10 every slow third-party call collapses into +Inf,
    | where histogram_quantile can only answer +Inf for p95.
    |
    */

    'buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0],

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    |
    | Every enabled label multiplies the number of series Prometheus stores; the
    | histogram alone is 16 series per distinct combination.
    |
    | The label set is fixed the first time a metric is registered in a given
    | registry — the Prometheus client caches collectors by name and ignores the
    | labels handed to later calls. Changing these after the process has
    | recorded a request produces an arity error, not a new metric.
    |
    */

    'labels' => [
        'host' => true,
        'method' => true,
        'endpoint' => true,
        'status_class' => true,
        'service' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint Normalisation
    |--------------------------------------------------------------------------
    |
    | The `endpoint` label is where cardinality goes wrong. A path is normalised
    | by replacing identifier-shaped segments with `:id` and then truncated to
    | `max_segments`, so /v1/users/8134/orders/99/items becomes /v1/users/:id/*.
    |
    | `patterns` is the only hard bound. When it is non-empty a normalised path
    | must match one of these Str::is() patterns or it is labelled `other`. If
    | you talk to an API with an open-ended URL space, declare your endpoints
    | here — everything above this line is a heuristic, and heuristics leak.
    |
    */

    'endpoints' => [
        'max_segments' => 3,
        'max_segment_length' => 40,
        'max_length' => 120,
        'patterns' => [],
        'other_label' => 'other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Hosts
    |--------------------------------------------------------------------------
    |
    | Str::is() patterns. Matching requests are not recorded at all.
    |
    | Put your telemetry backends here. An exporter that ships spans or metrics
    | over HTTP will otherwise generate the very traffic it is reporting on.
    |
    */

    'ignore_hosts' => [
        // 'localhost',
        // '*.datadoghq.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Maps a logical service name to Str::is() host patterns, for the optional
    | `service` label. Useful when one dependency is spread over many hostnames
    | (a1.cdn.example.com .. a99.cdn.example.com); labelling by service instead
    | of host collapses those into a single series.
    |
    */

    'services' => [
        // 'stripe' => ['api.stripe.com', 'files.stripe.com'],
    ],

    'default_service' => 'unknown',

    /*
    |--------------------------------------------------------------------------
    | Registry
    |--------------------------------------------------------------------------
    |
    | `auto` adopts a Prometheus\CollectorRegistry already bound in the
    | container. spatie/laravel-prometheus binds exactly that, and its export
    | route renders the whole registry, so httptheus metrics appear at its
    | /prometheus with no further configuration — and the storage settings below
    | are then ignored, because that registry brought its own adapter.
    |
    | Set to `own` to always build a private registry from `storage`.
    |
    */

    'registry' => env('HTTPTHEUS_REGISTRY', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where counters live between the request that increments them and the
    | scrape that reads them.
    |
    | auto    APCu when the extension is loaded and enabled, otherwise memory.
    |         It never guesses at a Redis connection, so an application that
    |         stores metrics in Redis must say so.
    | apcu    Per-host shared memory. The right answer under PHP-FPM. Not shared
    |         with CLI processes, so queue workers need redis.
    | apcng   APCu with a cached metadata index; faster with many series.
    | redis   Shared across processes and hosts. Works for queue workers.
    |         Needs ext-redis. Every instance then reports identical totals, so
    |         scrape exactly one target or you will count them N times over.
    | predis  The same, over the pure-PHP predis/predis client, for
    |         applications that never installed ext-redis. Same sharing and
    |         same single-target caveat.
    | memory  Discarded at the end of the request. Under PHP-FPM every scrape
    |         returns nothing at all. Useful in tests, under Octane, or in a
    |         single long-lived process that also serves the scrape.
    |
    */

    'storage' => [
        'driver' => env('HTTPTHEUS_STORAGE', 'auto'),
        'prefix' => env('HTTPTHEUS_STORAGE_PREFIX', 'httptheus'),

        /*
         * Shared by the `redis` and `predis` drivers.
         *
         * There is no `database` key: the Prometheus client's Redis adapters
         * have no such option, and offering one they would silently ignore is
         * worse than not offering it.
         */
        'redis' => [
            'host' => env('HTTPTHEUS_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('HTTPTHEUS_REDIS_PORT', 6379),
            'password' => env('HTTPTHEUS_REDIS_PASSWORD'),
            'timeout' => (float) env('HTTPTHEUS_REDIS_TIMEOUT', 0.1),
            'read_timeout' => (string) env('HTTPTHEUS_REDIS_READ_TIMEOUT', '10'),
            'persistent_connections' => (bool) env('HTTPTHEUS_REDIS_PERSISTENT', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrape Route
    |--------------------------------------------------------------------------
    |
    | httptheus's own metrics endpoint. Leave it on alongside
    | spatie/laravel-prometheus if you like — both render the same registry, and
    | you scrape whichever you prefer.
    |
    | The middleware list is empty on purpose. A scrape carries no session and
    | no CSRF token, and putting it behind the `web` group would start a session
    | for every scrape.
    |
    | With no `allowed_ips` and no Httptheus::auth() callback the route answers
    | only in the local environment, so an install someone forgot about does not
    | publish the application's dependency map to the internet.
    |
    */

    'route' => [
        'enabled' => env('HTTPTHEUS_ROUTE', true),
        'domain' => env('HTTPTHEUS_DOMAIN'),
        'path' => env('HTTPTHEUS_PATH', 'httptheus/metrics'),
        'middleware' => [],
        'allowed_ips' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('HTTPTHEUS_ALLOWED_IPS', '')))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo
    |--------------------------------------------------------------------------
    |
    | Registers `httptheus:demo-traffic`, which generates synthetic outbound
    | calls for the docker compose demo stack. Never enable this in a real
    | application: it exists to give the shipped Grafana dashboard something to
    | draw.
    |
    */

    'demo' => env('HTTPTHEUS_DEMO', false),

    'demo_target' => env('HTTPTHEUS_DEMO_TARGET', 'http://localhost:8080'),

];
