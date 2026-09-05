<?php

namespace BoringO11y\Httptheus\Http\Controllers;

use BoringO11y\Httptheus\Metrics\RegistryFactory;
use Illuminate\Http\Response;
use Prometheus\RenderTextFormat;

class MetricsController
{
    public function __invoke(RegistryFactory $registries): Response
    {
        return new Response(
            (new RenderTextFormat)->render($registries->registry()->getMetricFamilySamples()),
            200,
            ['Content-Type' => RenderTextFormat::MIME_TYPE],
        );
    }
}
