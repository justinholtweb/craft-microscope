<?php

namespace justinholtweb\microscope\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\Plugin;
use yii\web\Response;

/**
 * The landing screen: current score, what's outstanding, and how it's trending.
 */
class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->scans->getLatestScan();
        $result = $scan !== null ? $plugin->scans->getResultForScan((int)$scan->id) : null;

        $previous = $scan !== null ? $plugin->scans->getPreviousScan((int)$scan->id) : null;
        $previousResult = $previous !== null ? $plugin->scans->getResultForScan((int)$previous->id) : null;

        return $this->renderTemplate('microscope/dashboard/index', [
            'title' => Craft::t('microscope', 'Microscope'),
            'scan' => $scan,
            'result' => $result,
            'previous' => $previous,
            'newFindings' => $result?->newSince($previousResult) ?? [],
            'resolvedFindings' => $result?->resolvedSince($previousResult) ?? [],
            'history' => $plugin->scans->getScoreHistory(20),
            'categories' => Category::all(),
            'checkCount' => $plugin->checks->count(),
            'nextScheduled' => $plugin->schedules->nextRunAt(),
            'canScan' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SCAN),
            'selectedSubnavItem' => 'dashboard',
        ]);
    }
}
