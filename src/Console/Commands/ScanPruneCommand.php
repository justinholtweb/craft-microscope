<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Services\Scans;

/**
 * Deletes old scans beyond the retention limit.
 */
class ScanPruneCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:scan:prune {keep? : How many scans to keep (default: the retention setting)}';

    #[\Override]
    protected $description = 'Deletes Microscope scans beyond the retention limit.';

    #[\Override]
    protected $aliases = ['microscope/scan/prune'];

    public function handle(Scans $scans): int
    {
        $keep = $this->argument('keep');
        $deleted = $scans->prune($keep !== null ? (int) $keep : null);

        $this->info(sprintf('Deleted %d scans.', $deleted));

        return self::SUCCESS;
    }
}
