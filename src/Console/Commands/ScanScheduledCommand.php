<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Notifier;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;
use Throwable;

/**
 * Runs the scheduled scan if one is due. Laravel's scheduler runs this hourly (see
 * Plugin::schedule()); it can also be run from cron directly, as on Microscope 5.
 *
 * Runs inline rather than queueing: the scheduler is already a background context, and the exit
 * status then reflects the scan rather than the act of queueing one.
 */
class ScanScheduledCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:scan:scheduled';

    #[\Override]
    protected $description = 'Runs the scheduled Microscope scan, if one is due.';

    #[\Override]
    protected $aliases = ['microscope/scan/scheduled'];

    public function handle(Settings $settings, Schedules $schedules, Scans $scans, Notifier $notifier): int
    {
        if (! $settings->scheduleEnabled) {
            $this->line('<fg=gray>Scheduled scans are turned off.</>');

            return self::SUCCESS;
        }

        if (! $schedules->isDue()) {
            $this->line(sprintf('<fg=gray>Not due yet. Next scan: %s</>', $schedules->nextRunAt()?->format('Y-m-d H:i') ?? 'unknown'));

            return self::SUCCESS;
        }

        try {
            $scan = $scans->run(new ScanConfig(trigger: ScanConfig::TRIGGER_SCHEDULED));
        } catch (Throwable $e) {
            $this->error('The scheduled scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $notifier->scheduledScanFinished($scan);

        $this->info(sprintf('Scan #%d complete — score %d/100, %d problems.', $scan->id, $scan->score ?? 0, $scan->problemCount()));

        return self::SUCCESS;
    }
}
