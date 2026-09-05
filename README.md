# httptheus

Prometheus metrics for your application's **outbound** HTTP traffic, and a Grafana dashboard
to read them with.

httptheus instruments Laravel's HTTP client globally — `Http::get()`, `Http::pool()`, and
anything else that goes through the facade — and ships the same middleware for raw Guzzle
handler stacks, so SDKs that never touch Laravel's client are covered too. It records the
duration, host, endpoint and outcome of every transfer, and nothing else: no bodies, no
headers, no database.

If you already run [spatie/laravel-prometheus](https://github.com/spatie/laravel-prometheus),
integration is nothing at all — see [Exporting](#exporting).

```
composer require boring-o11y/httptheus
```

That is the whole installation. In `local` the metrics are at `/httptheus/metrics`
immediately; see [Access](#access) before scraping anything else.

## What it records

Two metrics, deliberately.

**`httptheus_client_request_duration_seconds`** — a histogram labelled `host`, `method`,
`endpoint` and `status_class` (`2xx`, `3xx`, `4xx`, `5xx`, or `error` for a transfer that
never got a response).

```promql
# throughput
rate(httptheus_client_request_duration_seconds_count[5m])

# p95 latency to one host
histogram_quantile(0.95, sum by (le) (
  rate(httptheus_client_request_duration_seconds_bucket{host="api.stripe.com"}[5m])
))
```

**`httptheus_client_request_errors_total`** — a counter labelled `host`, `method`,
`endpoint` and `reason`, incremented only when a transfer produced no response at all.
`reason` is one of `timeout`, `dns`, `connection_refused`, `tls`, `network` or `other`,
read from cURL's error number.

There is no `requests_total`: it would carry exactly the numbers the histogram already
publishes as `_count`. And an HTTP error status is not counted twice — it is already
described by `status_class`. The error counter exists for the one thing a status class
cannot tell you: whether you are timing out, or DNS is broken, or their certificate
expired. It creates no series at all for a host that never fails.

A gauge, `httptheus_client_requests_in_flight`, is available but **off by default**: it
increments on entry and decrements on exit, so a fatal error between the two leaks a
permanent `+1` into shared storage.

### Two things to know about the numbers

They count **transfers, not calls**. The middleware sits inside Guzzle's redirect handling,
and Laravel's `retry()` re-enters the whole stack, so a redirect chain and a retried request
each record every hop. That is the honest reading — each hop has its own latency and its own
way to fail — but it means `_count` will exceed what your code thinks it did.

Recording never breaks the call it observes. Guzzle rethrows anything escaping its stats hook
as the request's own failure, so every path here is wrapped and reported through your
exception handler instead.

## Cardinality

The `endpoint` label is the one that can hurt you. Every distinct value is 16 series in the
histogram alone.

Paths are normalised before they become a label: numeric, UUID, ULID and hex segments become
`:id`, any segment longer than 40 characters becomes `:id`, and only the first three segments
are kept — `/v1/users/8134/orders/99/items` becomes `/v1/users/:id/*`, where the trailing `*`
says the label is a prefix.

That handles the common case. It is still a heuristic, and the only hard bound is the
allow-list:

```php
'endpoints' => [
    'patterns' => ['/v1/users*', '/v1/orders*', '/v2/*'],
],
```

With `patterns` set, anything unmatched becomes a single `other` series. It is opt-in because
a default list would silently relabel every endpoint of everyone who never configured one.
If you would rather not think about it, `'labels' => ['endpoint' => false]` drops the label
entirely.

The damage is durable if you get it wrong: the Prometheus client never expires a label
combination, so bad series survive in APCu or Redis until `php artisan httptheus:wipe`. The
dashboard's Diagnostics row has a **Distinct endpoint labels** panel so you find out before
Prometheus does.

## Storage

This is the setting that decides whether your scrape returns anything at all. Counters have
to outlive the request that incremented them.

| Runtime | `HTTPTHEUS_STORAGE` | Why |
|---|---|---|
| PHP-FPM | `apcu` | Shared memory per host, survives between requests |
| Queue workers | `redis` | APCu does not cross process boundaries, and a worker has no endpoint to scrape |
| Octane, or one long-lived process | `memory` is fine | The process that records also serves the scrape |

The default is `auto`: APCu when the extension is loaded and enabled, otherwise `memory`.

**`memory` under PHP-FPM returns an empty scrape every time** — the request that served it
never saw the increments. If `/httptheus/metrics` is blank, this is why. `php artisan about`
reports the driver actually in use.

With `redis`, every application instance reports the **same** totals. Scrape exactly one
target, or you will count all of your traffic N times over.

## Exporting

httptheus writes into whatever `Prometheus\CollectorRegistry` your container already has, and
builds its own only if there isn't one.

spatie/laravel-prometheus binds exactly that class, and its export route renders the entire
registry — so if you have it installed, httptheus metrics appear at its `/prometheus` with no
configuration, no collector to register, and no code on either side. Its storage adapter is
then the one in effect, and the `storage` settings here are ignored.

One caveat if you go that route: spatie's cache-backed adapter reads, mutates and writes each
metric, which is not atomic — concurrent workers silently lose increments. Set
`'registry' => 'own'` with `HTTPTHEUS_STORAGE=redis` if you would rather have the Prometheus
client's native Redis adapter, which increments atomically, at the cost of a second endpoint.

Otherwise httptheus serves its own at `/httptheus/metrics`.

## Access

A scrape reveals every host your application talks to, so an install nobody has configured
answers only in `local`. Open it up with either:

```php
// config/httptheus.php
'route' => ['allowed_ips' => ['10.0.0.0/8']],
```

or a gate of your own, in a service provider:

```php
Httptheus::auth(fn (Request $request) => $request->user()?->isAdmin() ?? false);
```

The route carries no `web` middleware — a scrape has no session and no CSRF token, and the
`web` group would start a session for every one.

## Raw Guzzle

For an SDK that builds its own client and never goes through Laravel:

```php
use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;

$stack = HandlerStack::create();
$stack->push(app(RecordHttpMetrics::class));

$client = new Client(['handler' => $stack]);
```

It is the same object the Laravel client uses, and it is safe to push onto a stack that is
already instrumented — a transfer is recorded once regardless.

## Grafana

`dashboards/httptheus.json` is a versioned dashboard covering hosts, endpoints and failures.
Import it, or publish it with the rest of your provisioning:

```
php artisan vendor:publish --tag=httptheus-dashboard
```

Every panel reads from a `datasource` variable, so it imports onto any Prometheus datasource,
and filters chain through `job` → `host` → `method` → `endpoint`.

To see it running against live traffic:

```
docker compose --profile demo up
```

That brings up an instrumented app, an httpbin for it to call, a traffic generator, and a
Prometheus and Grafana wired together. Grafana is on <http://localhost:3000> with the
dashboard already loaded; the raw scrape is on <http://localhost:8088/httptheus/metrics>.

## Configuration

`php artisan vendor:publish --tag=httptheus-config` publishes `config/httptheus.php`, which
documents every key. The ones worth knowing before you need them:

| Key | Default | |
|---|---|---|
| `enabled` | `true` | Master switch; false registers nothing |
| `storage.driver` | `auto` | See [Storage](#storage) |
| `endpoints.patterns` | `[]` | The hard bound on cardinality |
| `ignore_hosts` | `[]` | `Str::is()` patterns. **Put your telemetry backends here** — an exporter shipping over HTTP otherwise generates the traffic it reports on |
| `labels.service` | `false` | Adds a logical name resolved from `services`, for one dependency spread over many hostnames |
| `buckets` | 5ms → 60s | Runs past the Prometheus client's own defaults, which stop at 10s — too low for third-party APIs |
| `metrics.in_flight` | `false` | See above |
| `route.path` | `httptheus/metrics` | |

## Requirements

PHP 8.2+, Laravel 12 or 13. `ext-apcu` or `ext-redis` for anything but Octane.

## Testing

```
docker compose run --rm tests
docker compose run --rm tests vendor/bin/phpunit --filter LabelResolverTest
docker compose run --rm tests vendor/bin/phpstan analyse
```

The `Integration` suite covers the spatie adoption path and needs PHP 8.4, which is that
package's floor; CI runs it in a job of its own.
