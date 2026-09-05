<?php

namespace BoringO11y\Httptheus\Console;

use BoringO11y\Httptheus\Metrics\RegistryFactory;
use Illuminate\Console\Command;

class WipeCommand extends Command
{
    protected $signature = 'httptheus:wipe';

    protected $description = 'Delete every metric httptheus has stored';

    public function handle(RegistryFactory $registries): int
    {
        // The Prometheus client never expires a label combination, so a deploy
        // that briefly emitted an unbounded `endpoint` label leaves those series
        // in APCu or Redis for as long as the storage lives. This is the only
        // way to get rid of them.
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
