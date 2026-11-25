<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Services\Notifier;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;

use function CraftCms\Cms\t;

/**
 * Runs a scan on the queue.
 *
 * A full scan reads every template on the site and issues a dozen diagnostic queries, which is
 * more than a web request should be asked to do — so the control panel, MCP and the scheduler all
 * queue this rather than scanning inline.
 */
class RunScan extends Job
{
    /** A scan on a large site reads thousands of templates. */
    public int $timeout = 900;

    /** A scan is a diagnostic run; repeating a failed one straight away would fail the same way. */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $config  The scan config as an array: payloads must serialise.
     * @param  bool  $notify  Send the scheduled-scan notice afterwards.
     */
    public function __construct(
        public array $config = [],
        public ?int $creatorId = null,
        public bool $notify = false,
    ) {
        parent::__construct();
    }

    public function handle(Scans $scans, Schedules $schedules, Notifier $notifier): void
    {
        try {
            $scan = $scans->run(
                ScanConfig::fromArray($this->config),
                fn (string $message, float $fraction) => $this->setProgress((int) max(0, min(100, round($fraction * 100))), $message),
                $this->creatorId,
            );

            if ($this->notify) {
                $notifier->scheduledScanFinished($scan);
            }
        } finally {
            // Released whether the scan succeeded or threw: a failed scan that left the flag set
            // would suppress scheduled scans until it expired.
            if (ScanConfig::fromArray($this->config)->trigger === ScanConfig::TRIGGER_SCHEDULED) {
                $schedules->markQueueDrained();
            }
        }
    }

    protected function defaultDescription(): string
    {
        return t('Scanning the site for performance issues', category: 'microscope');
    }
}
