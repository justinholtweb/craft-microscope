<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Services\Scans;

/**
 * Lists recent scans.
 */
class ScanListCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:scan:list {--limit=20 : How many scans}';

    #[\Override]
    protected $description = 'Lists recent Microscope scans.';

    #[\Override]
    protected $aliases = ['microscope/scan/list'];

    public function handle(Scans $scans): int
    {
        $list = $scans->getScans(max(1, (int) $this->option('limit')));

        if ($list === []) {
            $this->line('<fg=gray>No scans yet. Run `php artisan microscope:scan:run`.</>');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Date', 'Trigger', 'Score', 'Findings'], array_map(fn (Scan $scan) => [
            '#'.$scan->id,
            $scan->dateCreated->format('Y-m-d H:i'),
            $scan->trigger,
            $scan->score !== null ? $scan->score.'/100' : $scan->status,
            sprintf('%d critical, %d warnings, %d notices', $scan->criticalCount, $scan->warningCount, $scan->noticeCount),
        ], $list));

        return self::SUCCESS;
    }
}
