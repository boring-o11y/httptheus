<?php

namespace BoringO11y\Httptheus\Console;

use BoringO11y\Httptheus\Metrics\RegistryFactory;
use BoringO11y\Httptheus\Metrics\StorageFactory;
use Illuminate\Console\Command;

class WipeCommand extends Command
{
    protected $signature = 'httptheus:wipe';

    protected $description = 'Delete every metric httptheus has stored';

    public function handle(RegistryFactory $registries, StorageFactory $storage): int
    {
        // The registry belongs to another package, and wiping it deletes every
        // metric the application stores there, not only ours.
        if ($registries->isAdopted()) {
            $this->components->error(
                'httptheus is writing into a registry bound by another package, so wiping it would delete '
                . 'all of that registry\'s metrics. Clear it with that package\'s own tooling instead.',
            );

            return self::FAILURE;
        }

        // APCu is per-SAPI shared memory: this process cannot see the segment
        // PHP-FPM serves the scrape from, and memory storage dies with the
        // request. Wiping either here would report success and change nothing.
        $driver = $storage->driver();

        if (in_array($driver, ['apcu', 'apcng', 'memory'], true)) {
            $this->components->error(
                "The [{$driver}] storage driver cannot be wiped from the command line: this process does not share "
                . 'its memory with the web server. Reloading PHP-FPM (or your Octane workers) clears it.',
            );

            return self::FAILURE;
        }

        // The Prometheus client never expires a label combination, so a deploy
        // that briefly emitted an unbounded `endpoint` label leaves those series
        // in Redis for as long as the storage lives. This is the only way to
        // get rid of them.
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $registries->registry()->wipeStorage();

        $this->components->info('httptheus metrics storage wiped.');

        return self::SUCCESS;
    }

    private function confirmToProceed(): bool
    {
        if ($this->option('no-interaction') || ! $this->getLaravel()->environment('production')) {
            return true;
        }

        return $this->components->confirm(
            'This deletes every metric httptheus has stored, including history not yet scraped. Continue?',
        );
    }
}
