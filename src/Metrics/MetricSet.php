<?php

namespace BoringO11y\Httptheus\Metrics;

use BoringO11y\Httptheus\Httptheus;
use BoringO11y\Httptheus\Recording\LabelResolver;
use Prometheus\Counter;
use Prometheus\Gauge;
use Prometheus\Histogram;

class MetricSet
{
    private ?Histogram $duration = null;

    private ?Counter $errors = null;

    private ?Gauge $inFlight = null;

    public function __construct(
        private readonly RegistryFactory $registries,
        private readonly LabelResolver $labels,
    ) {}

    /**
     * The duration histogram.
     *
     * getOrRegisterHistogram() caches on namespace and name alone: the labels
     * and buckets handed to a second call are ignored, and the mismatch only
     * surfaces later as an arity error at observe time. The first registration
     * in a registry therefore decides both for that registry's lifetime, which
     * is why nothing here recomputes them per call.
     */
    public function duration(): Histogram
    {
        return $this->duration ??= $this->registries->registry()->getOrRegisterHistogram(
            $this->namespace(),
            Httptheus::METRIC_DURATION,
            'Duration of outbound HTTP requests in seconds, observed from Guzzle transfer stats.',
            $this->labels->names(),
            array_map('floatval', (array) config('httptheus.buckets')),
        );
    }

    public function errors(): Counter
    {
        return $this->errors ??= $this->registries->registry()->getOrRegisterCounter(
            $this->namespace(),
            Httptheus::METRIC_ERRORS,
            'Outbound HTTP requests that failed before a response was received, by transport error kind.',
            $this->labels->errorNames(),
        );
    }

    public function inFlight(): Gauge
    {
        return $this->inFlight ??= $this->registries->registry()->getOrRegisterGauge(
            $this->namespace(),
            Httptheus::METRIC_IN_FLIGHT,
            'Outbound HTTP requests currently in flight.',
            ['host'],
        );
    }

    private function namespace(): string
    {
        return (string) config('httptheus.namespace', 'httptheus');
    }
}
