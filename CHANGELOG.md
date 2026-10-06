# Changelog

All notable changes to httptheus are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Removed

- Support for PHP 8.2 and 8.3. The package now requires PHP 8.4 or later, and CI runs on PHP 8.4 and 8.5.

## [0.3.0] - 2026-10-05

### Upgrading

- **`redis` driver:** keys now live under `storage.prefix` (default `httptheus`). Series
  stored by 0.2 under the shared `PROMETHEUS_*` keyspace are not read any more, so counters
  restart from zero. The old keys are not removed; delete them by hand if nothing else on
  that Redis uses that keyspace.
- **In-flight gauge:** it is now labelled by whichever of `host` and `service` are enabled,
  instead of always by `host`. Update any queries or alerts that use it.

### Fixed

- The Guzzle middleware held by Laravel's HTTP client factory now resolves the recorder on
  every transfer, so it follows the current registry across Octane requests instead of
  keeping the one from the first request.
- A handler that throws before returning a promise no longer leaves the request counted as
  in flight. The failure is recorded.
- An HTTP error raised as an exception keeps its response status (`4xx`/`5xx`) on both
  Guzzle 7 and 8, rather than being recorded as `error`.
- Fulfilled values that are not responses are passed through untouched.
- When transfer stats carry no transfer time (as with `Http::fake()`), duration falls back to
  wall-clock time instead of being recorded as zero.
- Turning the `host` label off now also removes it from the in-flight gauge, which kept a
  series per raw hostname.
- The `redis` driver now prefixes its keys with `storage.prefix`, as the `predis` driver
  already did. Before, it shared the `PROMETHEUS_*` keyspace with other users of the
  Prometheus client, and `httptheus:wipe` deleted their metrics too.

### Changed

- `httptheus:wipe` now refuses to run against an adopted registry, and against storage the
  CLI cannot reach (`apcu`, `apcng`, `memory`). Wiping an adopted registry deleted another
  package's metrics, and wiping APCu or memory storage reported success while changing nothing.
  Reload PHP-FPM or your Octane workers to clear APCu.
- `php artisan about` now shows `auto` storage as resolved separately in each process,
  rather than presenting the CLI's choice as the one web workers use.

## [0.2.0] - 2026-09-05

### Added

- A `predis` storage driver for applications that talk to Redis through the pure-PHP predis
  client and have no `ext-redis`. It reads the same `storage.redis` settings as the `redis`
  driver. `auto` is unchanged and still never guesses at a Redis connection.

## [0.1.0] - 2026-09-05

Initial release.

### Added

- Global instrumentation of Laravel's HTTP client, plus the same middleware for raw Guzzle
  handler stacks. Transfers are recorded through Guzzle's `on_stats` hook, which is chained
  onto any existing hook rather than replacing it.
- `httptheus_client_request_duration_seconds` histogram, labelled `host`, `method`,
  `endpoint` and `status_class`. Buckets run to 60s.
- `httptheus_client_request_errors_total` counter, labelled with the transport failure kind
  (timeout, DNS, refused, TLS, …) taken from curl's errno.
- `httptheus_client_requests_in_flight` gauge, labelled `host`, off by default.
- Export by adoption: metrics are written into the container's existing
  `Prometheus\CollectorRegistry` (for example spatie/laravel-prometheus) or into one of
  httptheus's own, served at `/httptheus/metrics`.
- A Grafana dashboard, publishable with `vendor:publish --tag=httptheus-dashboard`.
- Support for Laravel 12 and 13, and Guzzle 7 and 8.

[0.3.0]: https://github.com/boring-o11y/httptheus/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/boring-o11y/httptheus/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/boring-o11y/httptheus/releases/tag/v0.1.0
