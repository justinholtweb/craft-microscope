<?php

namespace justinholtweb\microscope\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\Plugin;

/**
 * Runs a scan on the queue.
 *
 * A full scan reads every template on the site and issues a dozen diagnostic queries, which is
 * more than a web request should be asked to do — so the control panel queues this and watches it,
 * and the scheduler pushes it directly.
 */
class ScanJob extends BaseJob
{
    /**
     * @var array<string, mixed> The scan config as an array. Queue payloads have to survive
     *                           serialisation, so models don't go on the job.
     */
    public array $config = [];

    /**
     * @var int|null Who asked for it, where anybody did.
     */
    public ?int $creatorId = null;

    /**
     * @var bool Whether to send the configured notification afterwards.
     */
    public bool $notify = false;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $config = ScanConfig::fromArray($this->config);

        try {
            $scan = $plugin->scans->run(
                $config,
                function(string $message, ?float $progress = null) use ($queue) {
                    $this->setProgress($queue, $progress ?? 0, $message);
                },
                $this->creatorId,
            );

            if ($this->notify) {
                $plugin->schedules->notify($scan);
            }
        } finally {
            // Released whether the scan succeeded or threw. A failed scan that left the flag set
            // would suppress every scheduled scan until the flag expired — turning one bad run
            // into two hours of silence.
            $plugin->schedules->markQueueDrained();
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('microscope', 'Scanning the site for performance issues');
    }
}
