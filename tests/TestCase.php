<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Httptheus;
use BoringO11y\Httptheus\HttptheusServiceProvider;
use BoringO11y\Httptheus\Metrics\MetricSet;
use BoringO11y\Httptheus\Metrics\RegistryFactory;
use BoringO11y\Httptheus\Recording\HttpMetricsRecorder;
use Orchestra\Testbench\TestCase as Orchestra;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

abstract class TestCase extends Orchestra
{
    protected InMemory $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh InMemory registry per test is the only reset the Prometheus
        // client offers that touches no shared state, and it is what makes the
        // rendered-output assertions below deterministic.
        //
        // Binding it also means every test exercises the adoption path — the
        // same code path spatie/laravel-prometheus triggers.
        $this->storage = new InMemory;
        $this->app->instance(CollectorRegistry::class, new CollectorRegistry($this->storage, false));

        Httptheus::$authUsing = null;
        Httptheus::$endpointResolver = null;
    }

    protected function tearDown(): void
    {
        Httptheus::$authUsing = null;
        Httptheus::$endpointResolver = null;

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [HttptheusServiceProvider::class];
    }

    /**
     * Exactly what a scrape would return, so assertions read like the thing
     * Prometheus actually sees.
     */
    protected function render(): string
    {
        return (new RenderTextFormat)->render(
            $this->app->make(RegistryFactory::class)->registry()->getMetricFamilySamples()
        );
    }

    protected function assertSample(string $line): void
    {
        $this->assertStringContainsString($line, $this->render());
    }

    protected function assertNoSample(string $line): void
    {
        $this->assertStringNotContainsString($line, $this->render());
    }

    /**
     * The renderer emits a bare newline for an empty registry, not an empty
     * string, so trim before comparing.
     */
    protected function assertNothingRecorded(): void
    {
        $this->assertSame('', trim($this->render()));
    }

    /**
     * Change configuration before anything has been registered.
     *
     * The Prometheus client caches collectors by name and ignores the labels
     * handed to later calls, so a label change applied after the first
     * observation throws an arity error instead of taking effect.
     *
     * @param  array<string, mixed>  $config
     */
    protected function withConfig(array $config): void
    {
        config($config);

        $this->app->forgetInstance(MetricSet::class);
        $this->app->forgetInstance(HttpMetricsRecorder::class);
    }

    protected function recorder(): HttpMetricsRecorder
    {
        return $this->app->make(HttpMetricsRecorder::class);
    }
}
