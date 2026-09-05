<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Metrics\RegistryFactory;
use BoringO11y\Httptheus\Metrics\StorageFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;

class StorageFactoryTest extends TestCase
{
    private StorageFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->app->make(StorageFactory::class);
    }

    #[Test]
    public function it_builds_the_configured_adapter(): void
    {
        config(['httptheus.storage.driver' => 'memory']);

        $this->assertInstanceOf(InMemory::class, $this->factory->make());
    }

    #[Test]
    public function auto_prefers_apcu_when_it_is_available(): void
    {
        if (! extension_loaded('apcu') || ! apcu_enabled()) {
            $this->markTestSkipped('apcu is not enabled.');
        }

        config(['httptheus.storage.driver' => 'auto']);

        $this->assertSame('apcu', $this->factory->driver());
        $this->assertInstanceOf(APC::class, $this->factory->make());
    }

    #[Test]
    public function auto_falls_back_to_memory_without_apcu(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            $this->markTestSkipped('apcu is enabled, so auto resolves to it.');
        }

        config(['httptheus.storage.driver' => 'auto']);

        $this->assertSame('memory', $this->factory->driver());
    }

    #[Test]
    public function an_unknown_driver_names_itself_in_the_error(): void
    {
        config(['httptheus.storage.driver' => 'postgres']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('postgres');

        $this->factory->make();
    }

    #[Test]
    public function it_adopts_a_registry_already_bound_in_the_container(): void
    {
        // This is exactly what spatie/laravel-prometheus binds, and adopting it
        // is the whole of the integration: its export route renders the entire
        // registry, so our metrics appear there with no code on either side.
        $registries = $this->app->make(RegistryFactory::class);

        $this->assertTrue($registries->isAdopted());
        $this->assertSame($this->app->make(CollectorRegistry::class), $registries->registry());
    }

    #[Test]
    public function it_builds_its_own_registry_when_told_to(): void
    {
        config(['httptheus.registry' => 'own', 'httptheus.storage.driver' => 'memory']);

        $registries = new RegistryFactory($this->app, $this->factory);

        $this->assertFalse($registries->isAdopted());
        $this->assertNotSame($this->app->make(CollectorRegistry::class), $registries->registry());
    }

    #[Test]
    public function redis_options_drop_the_keys_that_were_never_set(): void
    {
        config([
            'httptheus.storage.driver' => 'redis',
            'httptheus.storage.redis' => ['host' => 'redis', 'port' => 6379, 'password' => null],
        ]);

        // A null password must not overwrite one set through the client's own
        // default options, so it is dropped rather than passed through.
        $options = (new \ReflectionMethod($this->factory, 'redisOptions'))->invoke($this->factory);

        $this->assertSame(['host' => 'redis', 'port' => 6379], $options);
    }
}
