<?php

namespace BoringO11y\Httptheus\Metrics;

use InvalidArgumentException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\APC;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;

class StorageFactory
{
    public function make(): Adapter
    {
        $driver = $this->driver();
        $prefix = (string) config('httptheus.storage.prefix', 'httptheus');

        return match ($driver) {
            'apcu' => new APC($prefix),
            'apcng' => new APCng($prefix),
            'redis' => new Redis($this->redisOptions()),
            'memory' => new InMemory,
            default => throw new InvalidArgumentException("Unknown httptheus storage driver [{$driver}]."),
        };
    }

    /**
     * The driver actually in use, with `auto` already resolved.
     *
     * Exposed because `php artisan about` reports it: the difference between
     * `apcu` and `memory` is the difference between a scrape that works under
     * PHP-FPM and one that is permanently empty, and that is the first thing
     * anyone needs to know when the endpoint comes back blank.
     */
    public function driver(): string
    {
        $driver = (string) config('httptheus.storage.driver', 'auto');

        if ($driver !== 'auto') {
            return $driver;
        }

        return $this->apcuAvailable() ? 'apcu' : 'memory';
    }

    /**
     * @return array<string, mixed>
     */
    private function redisOptions(): array
    {
        // Nulls are dropped rather than passed through: the client merges what
        // it is given over its own defaults, so an unset password arriving as
        // null would overwrite a password set with Redis::setDefaultOptions().
        return array_filter(
            (array) config('httptheus.storage.redis', []),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    private function apcuAvailable(): bool
    {
        return extension_loaded('apcu')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}
