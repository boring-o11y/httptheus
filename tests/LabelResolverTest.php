<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Httptheus;
use BoringO11y\Httptheus\Recording\LabelResolver;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class LabelResolverTest extends TestCase
{
    private LabelResolver $labels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->labels = $this->app->make(LabelResolver::class);
    }

    #[Test]
    #[DataProvider('paths')]
    public function it_normalizes_identifier_shaped_segments(string $path, string $expected): void
    {
        $this->assertSame($expected, $this->endpoint($path));
    }

    public static function paths(): array
    {
        return [
            'numeric id' => ['/users/12345', '/users/:id'],
            'nested numeric ids' => ['/users/8134/orders/99', '/users/:id/orders/*'],
            'uuid' => ['/v1/9f8b0c2e-1a3d-4b5c-8d7e-6f5a4b3c2d1e', '/v1/:id'],
            'ulid' => ['/v1/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/v1/:id'],
            'md5' => ['/files/d41d8cd98f00b204e9800998ecf8427e', '/files/:id'],
            'sha1' => ['/commits/da39a3ee5e6b4b0d3255bfef95601890afd80709', '/commits/:id'],
            'root' => ['/', '/'],
            'empty' => ['', '/'],
            'plain' => ['/v1/users', '/v1/users'],
            'trailing slash' => ['/v1/users/', '/v1/users'],
        ];
    }

    #[Test]
    public function it_caps_path_depth_and_says_so(): void
    {
        // The trailing marker matters: without it the label reads as the whole
        // endpoint rather than a prefix of it.
        $this->assertSame('/a/b/c/*', $this->endpoint('/a/b/c/d/e'));
        $this->assertSame('/a/b/c', $this->endpoint('/a/b/c'));
    }

    #[Test]
    public function a_segment_nobody_would_name_by_hand_is_treated_as_an_identifier(): void
    {
        $token = str_repeat('x', 64);

        $this->assertSame('/callback/:id', $this->endpoint("/callback/{$token}"));
    }

    #[Test]
    public function patterns_collapse_everything_unrecognised_into_one_series(): void
    {
        config(['httptheus.endpoints.patterns' => ['/v1/users*', '/v1/orders*']]);

        $this->assertSame('/v1/users/:id', $this->endpoint('/v1/users/42'));
        $this->assertSame('other', $this->endpoint('/v1/anything-else'));
    }

    #[Test]
    public function a_custom_resolver_wins_and_null_falls_back(): void
    {
        Httptheus::resolveEndpointUsing(
            fn ($uri) => str_starts_with($uri->getPath(), '/graphql') ? 'graphql' : null
        );

        $this->assertSame('graphql', $this->endpoint('/graphql'));
        $this->assertSame('/v1/users/:id', $this->endpoint('/v1/users/42'));
    }

    #[Test]
    public function it_truncates_a_long_endpoint(): void
    {
        // The depth cap already bounds most paths, but three segments just
        // under the per-segment limit still exceed the overall one, and a
        // custom resolver can return anything at all.
        $segment = str_repeat('a', 39);

        $this->assertSame(120, strlen($this->endpoint("/{$segment}/{$segment}/{$segment}")));

        Httptheus::resolveEndpointUsing(fn () => str_repeat('z', 500));

        $this->assertSame(120, strlen($this->endpoint('/v1/users')));
    }

    #[Test]
    public function it_matches_ignored_hosts_by_pattern(): void
    {
        config(['httptheus.ignore_hosts' => ['*.datadoghq.com', 'localhost']]);

        $this->assertTrue($this->labels->ignoresHost('api.datadoghq.com'));
        $this->assertTrue($this->labels->ignoresHost('localhost'));
        $this->assertFalse($this->labels->ignoresHost('api.example.com'));
    }

    #[Test]
    public function an_empty_ignore_list_ignores_nothing(): void
    {
        // Str::is() with an empty pattern list must not be read as "match all".
        $this->assertFalse($this->labels->ignoresHost('api.example.com'));
    }

    #[Test]
    public function it_resolves_a_service_from_host_patterns(): void
    {
        config(['httptheus.services' => ['stripe' => ['api.stripe.com', 'files.stripe.com']]]);

        $this->assertSame('stripe', $this->labels->service('files.stripe.com'));
        $this->assertSame('unknown', $this->labels->service('api.example.com'));
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_classifies_a_status(?int $status, bool $failed, string $expected): void
    {
        $this->assertSame($expected, $this->labels->statusClass($status, $failed));
    }

    public static function statuses(): array
    {
        return [
            [200, false, '2xx'],
            [301, false, '3xx'],
            [404, false, '4xx'],
            [503, false, '5xx'],
            [null, true, 'error'],
            [200, true, 'error'],
        ];
    }

    #[Test]
    #[DataProvider('errnos')]
    public function it_maps_a_curl_errno_to_a_transport_reason(mixed $errno, string $expected): void
    {
        $this->assertSame($expected, $this->labels->reason($errno));
    }

    public static function errnos(): array
    {
        return [
            'timeout' => [28, 'timeout'],
            'dns' => [6, 'dns'],
            'refused' => [7, 'connection_refused'],
            'expired certificate' => [60, 'tls'],
            'reset' => [56, 'network'],
            'unknown errno' => [99, 'other'],
            'a string from a custom handler' => ['boom', 'other'],
            'nothing at all' => [null, 'other'],
        ];
    }

    #[Test]
    public function the_error_counter_reuses_the_histogram_labels_with_reason_for_status(): void
    {
        $this->assertSame(['host', 'method', 'endpoint', 'status_class'], $this->labels->names());
        $this->assertSame(['host', 'method', 'endpoint', 'reason'], $this->labels->errorNames());

        $this->assertSame(
            ['api.example.com', 'GET', '/v1/users', 'timeout'],
            $this->labels->errorValues(['api.example.com', 'GET', '/v1/users', 'error'], 'timeout'),
        );
    }

    #[Test]
    public function disabling_a_label_removes_it_from_both_metrics(): void
    {
        config(['httptheus.labels.endpoint' => false, 'httptheus.labels.service' => true]);

        $this->assertSame(['host', 'service', 'method', 'status_class'], $this->labels->names());
        $this->assertSame(['host', 'service', 'method', 'reason'], $this->labels->errorNames());
    }

    private function endpoint(string $path): string
    {
        return $this->labels->endpoint(new GuzzleRequest('GET', "https://api.example.com{$path}"));
    }
}
