<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Http\Controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Reports;
use JustinHoltWeb\Microscope\Services\Scans;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Starting scans and reading them back.
 */
class ScansController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly Scans $scans,
    ) {}

    public function index(): CpScreenResponse
    {
        $canScan = (bool) currentUser()?->can(Plugin::PERMISSION_SCAN);

        $response = new CpScreenResponse()
            ->title(t('Scans', category: 'microscope'))
            ->selectedSubnavItem('scans')
            ->contentTemplate('microscope/scans/_index', [
                'scans' => $this->scans->getScans(100),
                'canScan' => $canScan,
            ]);

        if ($canScan) {
            $response->additionalButtonsTemplate('microscope/_partials/run-button', ['redirect' => 'microscope/scans']);
        }

        return $response;
    }

    public function show(int $scanId, Checks $checks, Reports $reports, Settings $settings): CpScreenResponse
    {
        $scan = $this->scans->getScanWithResult($scanId) ?? abort(404, t('Scan not found.', category: 'microscope'));
        $comparison = $this->scans->compareWithPrevious($scan);
        $canManage = (bool) currentUser()?->can(Plugin::PERMISSION_MANAGE);

        return new CpScreenResponse()
            ->title(t('Scan #{id}', ['id' => $scan->id], category: 'microscope'))
            ->selectedSubnavItem('scans')
            ->crumbs([
                ['label' => t('Microscope', category: 'microscope'), 'url' => cp_url('microscope')],
                ['label' => t('Scans', category: 'microscope'), 'url' => cp_url('microscope/scans')],
            ])
            ->additionalButtonsTemplate('microscope/scans/_detail-buttons', [
                'scan' => $scan,
                'pdfAvailable' => $reports->pdfIsAvailable(),
                'canEmail' => $canManage && $scan->isCompleted() && $settings->resolvedRecipients() !== [],
            ])
            ->contentTemplate('microscope/scans/_detail', [
                'scan' => $scan,
                'result' => $scan->result,
                'previous' => $comparison['previous'],
                'newFindings' => $comparison['new'],
                'resolvedFindings' => $comparison['resolved'],
                'categories' => Category::all(),
                'checks' => $checks,
                'pdfAvailable' => $reports->pdfIsAvailable(),
                'canManage' => $canManage,
            ]);
    }

    /**
     * Queues a scan. Never run inline: a full scan on a large site comfortably outlives a request.
     */
    public function store(Request $request): Response
    {
        $categories = array_values(array_filter((array) $request->input('categories', []), fn ($c) => is_string($c) && Category::exists($c)));

        $this->scans->queueScan(
            new ScanConfig(categories: $categories, trigger: ScanConfig::TRIGGER_MANUAL),
            currentUser()?->getCraftUserId(),
        );

        return $this->asSuccess(
            t('Scan started. It’ll appear here when it finishes.', category: 'microscope'),
            redirect: self::returnUrl($request),
        );
    }

    public function destroy(int $scanId): Response
    {
        if (! $this->scans->deleteScan($scanId)) {
            return $this->asFailure(t('Couldn’t delete that scan.', category: 'microscope'));
        }

        return $this->asSuccess(t('Scan deleted.', category: 'microscope'), redirect: cp_url('microscope/scans'));
    }

    /**
     * Whether a newer completed scan exists, for the "scanning…" state to poll.
     */
    public function status(Request $request): JsonResponse
    {
        $latest = $this->scans->getLatestScan();
        $since = (int) $request->query('since', '0');

        return response()->json([
            'finished' => $latest !== null && $latest->id > $since,
            'scanId' => $latest?->id,
            'score' => $latest?->score,
            'url' => $latest?->cpUrl(),
            'running' => $this->scans->isRunning(),
        ]);
    }

    /** Sends a scan's report to the configured recipients, on demand. */
    public function email(int $scanId, Reports $reports, Settings $settings): Response
    {
        $scan = $this->scans->getScanWithResult($scanId) ?? abort(404, t('Scan not found.', category: 'microscope'));
        $recipients = $settings->resolvedRecipients();

        if ($recipients === []) {
            return $this->asFailure(t('No notification recipients are configured.', category: 'microscope'));
        }

        try {
            $sent = $reports->email($scan, $recipients);
        } catch (Throwable $e) {
            Log::channel(Plugin::LOG_CHANNEL)->error('Couldn’t send the report: '.$e->getMessage(), ['exception' => $e]);

            return $this->asFailure(t('Couldn’t send the report: {message}', ['message' => $e->getMessage()], category: 'microscope'));
        }

        return $this->asSuccess(t('Report sent to {count} recipients.', ['count' => $sent], category: 'microscope'), redirect: $scan->cpUrl());
    }

    /**
     * Where a form asked to come back to: only Microscope's own control panel pages.
     */
    private static function returnUrl(Request $request): string
    {
        $path = (string) $request->input('return', 'microscope');

        return cp_url(preg_match('#^microscope(/[a-z0-9\-/]*)?$#', $path) === 1 ? $path : 'microscope');
    }
}
