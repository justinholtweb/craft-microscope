<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The landing screen: current score, what's outstanding, and how it's trending.
 */
class DashboardController
{
    public function __invoke(Scans $scans, Checks $checks, Schedules $schedules): CpScreenResponse
    {
        $scan = $scans->getLatestScan();
        $comparison = $scan !== null ? $scans->compareWithPrevious($scan) : ['previous' => null, 'new' => [], 'resolved' => []];
        $canScan = (bool) currentUser()?->can(Plugin::PERMISSION_SCAN);

        $response = new CpScreenResponse()
            ->title(t('Microscope', category: 'microscope'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('microscope/dashboard/_content', [
                'scan' => $scan,
                'result' => $scan?->result,
                'previous' => $comparison['previous'],
                'newFindings' => $comparison['new'],
                'resolvedFindings' => $comparison['resolved'],
                'history' => $scans->getScoreHistory(20),
                'categories' => Category::all(),
                'checkCount' => $checks->count(),
                'nextScheduled' => $schedules->nextRunAt(),
                'canScan' => $canScan,
                'scanRunning' => $scans->isRunning(),
            ]);

        if ($canScan && $scan !== null) {
            $response->additionalButtonsTemplate('microscope/_partials/run-button', ['redirect' => 'microscope']);
        }

        return $response;
    }
}
