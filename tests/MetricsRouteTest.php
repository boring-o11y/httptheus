<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Httptheus;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\Attributes\Test;
use Prometheus\RenderTextFormat;

class MetricsRouteTest extends TestCase
{
    #[Test]
    public function it_renders_the_registry_in_the_prometheus_text_format(): void
    {
        $this->app['env'] = 'local';

        $this->recorder()->recordStats(new TransferStats(
            new GuzzleRequest('GET', 'https://api.example.com/v1/users'),
            new GuzzleResponse(200),
            0.25,
        ));

        $this->get('/httptheus/metrics')
            ->assertOk()
            // Symfony appends the charset, which is what the exposition
            // format's canonical content type carries anyway.
            ->assertHeaderContains('Content-Type', RenderTextFormat::MIME_TYPE)
            ->assertSee('httptheus_client_request_duration_seconds_count', false);
    }

    #[Test]
    public function it_answers_in_the_local_environment_by_default(): void
    {
        $this->app['env'] = 'local';

        $this->get('/httptheus/metrics')->assertOk();
    }

    #[Test]
    public function an_unconfigured_install_does_not_answer_in_production(): void
    {
        // Scraping reveals every host the application talks to, so a forgotten
        // install stays quiet rather than publishing that to the internet.
        $this->app['env'] = 'production';

        $this->get('/httptheus/metrics')->assertForbidden();
    }

    #[Test]
    public function an_allowed_ip_gets_through_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['httptheus.route.allowed_ips' => ['10.0.0.0/8']]);

        $this->get('/httptheus/metrics', ['REMOTE_ADDR' => '10.1.2.3'])->assertOk();
        $this->call('GET', '/httptheus/metrics', server: ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    }

    #[Test]
    public function an_explicit_callback_overrides_everything(): void
    {
        $this->app['env'] = 'production';
        config(['httptheus.route.allowed_ips' => ['10.0.0.0/8']]);

        Httptheus::auth(fn () => true);

        $this->call('GET', '/httptheus/metrics', server: ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
    }
}
