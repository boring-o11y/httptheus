<?php

namespace BoringO11y\Httptheus\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * The path is read when routes are registered, so it has to be set before the
 * provider boots.
 */
class ConfiguredRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('httptheus.route.path', 'internal/metrics');
        $app['env'] = 'local';
    }

    #[Test]
    public function it_serves_the_configured_path(): void
    {
        $this->get('/internal/metrics')->assertOk();
        $this->get('/httptheus/metrics')->assertNotFound();
    }
}
