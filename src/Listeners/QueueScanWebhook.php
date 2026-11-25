<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Listeners;

use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Events\ScanCompleted;
use JustinHoltWeb\Microscope\Jobs\SendScanWebhook;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Support\Presenter;

/**
 * Queues the scan webhook for every completed scan, when one is configured.
 */
readonly class QueueScanWebhook
{
    public function __construct(
        private Settings $settings,
    ) {}

    public function handle(ScanCompleted $event): void
    {
        if ($this->settings->webhook() === null) {
            return;
        }

        SendScanWebhook::dispatch(self::payload($event));
    }

    /**
     * The webhook body: the scan summary, its problems (no remediation or evidence), and what's
     * new or resolved since the scan before.
     *
     * @return array<string, mixed>
     */
    public static function payload(ScanCompleted $event): array
    {
        $site = Sites::getPrimarySite();
        $previousResult = $event->previous !== null ? app(Scans::class)->getResultForScan($event->previous) : null;
        $brief = fn (Finding $f) => [
            'checkHandle' => $f->checkHandle,
            'category' => $f->category,
            'severity' => $f->severity,
            'title' => $f->title,
            'target' => $f->target,
            'fingerprint' => $f->fingerprint(),
        ];

        return [
            'event' => 'scan.completed',
            'site' => ['name' => $site->getName(), 'url' => $site->getBaseUrl()],
            'scan' => Presenter::scan($event->scan, $event->scan->cpUrl()),
            'previousScore' => $event->previous?->score,
            'problems' => array_map($brief, $event->result->problems()),
            'new' => array_map($brief, $event->result->newSince($previousResult)),
            'resolved' => array_map($brief, $event->result->resolvedSince($previousResult)),
            'sentAt' => now()->toAtomString(),
        ];
    }
}
