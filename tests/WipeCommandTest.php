<?php

namespace BoringO11y\Httptheus\Tests;

use PHPUnit\Framework\Attributes\Test;

class WipeCommandTest extends TestCase
{
    #[Test]
    public function it_refuses_to_wipe_a_registry_another_package_owns(): void
    {
        // The base test case binds a CollectorRegistry, so it is adopted here.
        $this->artisan('httptheus:wipe')
            ->expectsOutputToContain('another package')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_storage_the_command_line_cannot_reach(): void
    {
        config(['httptheus.registry' => 'own', 'httptheus.storage.driver' => 'apcu']);

        $this->artisan('httptheus:wipe')
            ->expectsOutputToContain('PHP-FPM')
            ->assertFailed();
    }
}
