<?php

namespace BoringO11y\Httptheus\Tests;

use BoringO11y\Httptheus\Httptheus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The shipped dashboard is only useful if it names metrics this package
 * actually emits, and a rename is exactly the kind of change that empties a
 * panel without failing anything.
 */
class DashboardJsonTest extends BaseTestCase
{
    private array $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $json = file_get_contents(__DIR__ . '/../dashboards/httptheus.json');
        $this->dashboard = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function every_metric_it_queries_is_one_we_emit(): void
    {
        $namespace = 'httptheus';

        $known = [];
        foreach (Httptheus::metricNames() as $name) {
            foreach (['', '_bucket', '_sum', '_count'] as $suffix) {
                $known[] = $namespace . '_' . $name . $suffix;
            }
        }

        $referenced = [];
        foreach ($this->expressions() as $expression) {
            preg_match_all('/httptheus_[a-z_]+/', $expression, $matches);
            $referenced = array_merge($referenced, $matches[0]);
        }

        $referenced = array_values(array_unique($referenced));

        $this->assertNotEmpty($referenced, 'The dashboard queries no httptheus metrics at all.');

        foreach ($referenced as $metric) {
            $this->assertContains($metric, $known, "The dashboard queries [{$metric}], which httptheus does not emit.");
        }
    }

    #[Test]
    public function it_keeps_a_stable_identity(): void
    {
        // Provisioning matches on uid; changing it orphans everyone's copy.
        $this->assertSame('httptheus-outbound', $this->dashboard['uid']);
        $this->assertSame('httptheus — Outbound HTTP', $this->dashboard['title']);
    }

    #[Test]
    public function every_panel_reads_from_the_datasource_variable(): void
    {
        // A hard-coded datasource uid imports cleanly on the machine it was
        // exported from and nowhere else.
        foreach ($this->panels() as $panel) {
            if ($panel['type'] === 'row' || $panel['type'] === 'text') {
                continue;
            }

            $this->assertSame(
                '${datasource}',
                $panel['datasource']['uid'] ?? null,
                "Panel [{$panel['title']}] does not use the datasource variable.",
            );
        }
    }

    #[Test]
    public function it_declares_the_variables_its_queries_use(): void
    {
        $declared = array_column($this->dashboard['templating']['list'], 'name');

        foreach (['datasource', 'job', 'host', 'method', 'endpoint', 'quantile'] as $name) {
            $this->assertContains($name, $declared);
        }

        foreach ($this->expressions() as $expression) {
            preg_match_all('/\$([a-z_]+)/', $expression, $matches);

            foreach ($matches[1] as $used) {
                if (str_starts_with($used, '__')) {
                    continue; // Grafana's own globals: $__rate_interval, $__range.
                }

                $this->assertContains($used, $declared, "Expression uses undeclared variable [\${$used}]: {$expression}");
            }
        }
    }

    #[Test]
    public function every_ratio_is_guarded_against_an_idle_series(): void
    {
        foreach ($this->expressions() as $expression) {
            if (! str_contains($expression, '/')) {
                continue;
            }

            // Bare division produces a gap when the denominator goes to zero,
            // which reads as "no data" on a panel someone is alerting on.
            $this->assertStringContainsString(
                'clamp_min',
                $expression,
                "Unguarded division in expression: {$expression}",
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function panels(): array
    {
        $panels = [];

        foreach ($this->dashboard['panels'] as $panel) {
            $panels[] = $panel;

            foreach ($panel['panels'] ?? [] as $nested) {
                $panels[] = $nested;
            }
        }

        return $panels;
    }

    /**
     * @return list<string>
     */
    private function expressions(): array
    {
        $expressions = [];

        foreach ($this->panels() as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                if (isset($target['expr'])) {
                    $expressions[] = $target['expr'];
                }
            }
        }

        foreach ($this->dashboard['templating']['list'] as $variable) {
            if (isset($variable['definition'])) {
                $expressions[] = $variable['definition'];
            }
        }

        return $expressions;
    }
}
