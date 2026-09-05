<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Guzzle\RecordHttpMetrics;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Test;

class DisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('httptheus.enabled', false);
    }

    #[Test]
    public function the_master_switch_registers_nothing_at_all(): void
    {
        $this->assertEmpty(array_filter(
            $this->app->make(Factory::class)->getGlobalMiddleware(),
            fn ($entry) => $entry instanceof RecordHttpMetrics,
        ));

        $this->get('/httptheus/metrics')->assertNotFound();
    }
}
