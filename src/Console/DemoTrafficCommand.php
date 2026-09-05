<?php

namespace BoringO11y\Httptheus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generates the traffic the shipped Grafana dashboard is built against.
 *
 * Registered only when `httptheus.demo` is on, which the docker compose demo
 * profile sets and nothing else should.
 */
class DemoTrafficCommand extends Command
{
    protected $signature = 'httptheus:demo-traffic {--forever} {--iterations=25}';

    protected $description = 'Generate synthetic outbound HTTP traffic for the demo stack';

    /**
     * Weighted so the dashboard shows a plausible shape rather than a flat
     * line: mostly fast successes, a tail of slow calls, a few errors, and one
     * host that is not resolvable at all so the transport error counter has
     * something to draw.
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const ROUTES = [
        ['GET', '/json', 30],
        ['GET', '/uuid', 20],
        ['GET', '/delay/1', 8],
        ['GET', '/delay/3', 3],
        ['POST', '/post', 12],
        ['GET', '/status/201', 6],
        ['GET', '/status/301', 3],
        ['GET', '/status/404', 5],
        ['GET', '/status/500', 3],
        ['GET', '/status/503', 2],
    ];

    public function handle(): int
    {
        $target = rtrim((string) config('httptheus.demo_target'), '/');
        $forever = (bool) $this->option('forever');
        $remaining = (int) $this->option('iterations');

        $this->components->info("Generating demo traffic against {$target}");

        while ($forever || $remaining-- > 0) {
            [$method, $path] = $this->weightedRoute();

            $this->send($target . $path, $method);

            // Roughly one call every 200-600ms, so a 5s scrape interval always
            // has something new to report.
            usleep(random_int(200_000, 600_000));

            if (random_int(1, 40) === 1) {
                $this->unresolvable();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function weightedRoute(): array
    {
        $roll = random_int(1, array_sum(array_column(self::ROUTES, 2)));

        foreach (self::ROUTES as [$method, $path, $weight]) {
            if (($roll -= $weight) <= 0) {
                return [$method, $path];
            }
        }

        return ['GET', '/json'];
    }

    private function send(string $url, string $method): void
    {
        try {
            Http::timeout(10)->send($method, $url, $method === 'POST' ? ['json' => ['demo' => true]] : []);
        } catch (Throwable) {
            // The point of the demo is the metric, which the middleware has
            // already recorded by the time the exception reaches here.
        }
    }

    private function unresolvable(): void
    {
        try {
            Http::timeout(2)->get('http://httptheus-demo-nowhere.invalid/health');
        } catch (Throwable) {
            //
        }
    }
}
