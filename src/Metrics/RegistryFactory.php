<?php

namespace BoringO11y\Httptheus\Metrics;

use Illuminate\Contracts\Container\Container;
use Prometheus\CollectorRegistry;

class RegistryFactory
{
    private ?CollectorRegistry $registry = null;

    public function __construct(
        private readonly Container $container,
        private readonly StorageFactory $storage,
    ) {}

    public function registry(): CollectorRegistry
    {
        return $this->registry ??= $this->resolve();
    }

    /**
     * Whether the registry we are writing to belongs to somebody else.
     *
     * The scrape route still renders it — two endpoints over one registry is
     * harmless — but `php artisan about` says so, because it also means the
     * storage configuration is not the one in effect.
     */
    public function isAdopted(): bool
    {
        return $this->shouldAdopt();
    }

    private function resolve(): CollectorRegistry
    {
        if ($this->shouldAdopt()) {
            return $this->container->make(CollectorRegistry::class);
        }

        // false: the client's default metrics are process gauges we never asked
        // to export, and they would appear under someone else's name.
        return new CollectorRegistry($this->storage->make(), false);
    }

    /**
     * spatie/laravel-prometheus binds Prometheus\CollectorRegistry, and its
     * export route renders getMetricFamilySamples() for the whole registry — so
     * writing into that binding is the entire integration, with no code on
     * either side. Any other package or application binding that class works
     * identically, which is why nothing here names spatie.
     */
    private function shouldAdopt(): bool
    {
        return config('httptheus.registry', 'auto') === 'auto'
            && $this->container->bound(CollectorRegistry::class);
    }
}
