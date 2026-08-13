<?php

namespace justinholtweb\microscope\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\microscope\jobs\ScanJob;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Starting scans and reading them back.
 */
class ScansController extends Controller
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

        return $this->renderTemplate('microscope/scans/index', [
            'title' => Craft::t('microscope', 'Scans'),
            'scans' => $plugin->scans->getScans(100),
            'canScan' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SCAN),
            'selectedSubnavItem' => 'scans',
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $scanId): Response
    {
        $plugin = Plugin::getInstance();
        $scan = $plugin->scans->getScanWithResult($scanId);

        if ($scan === null) {
            throw new NotFoundHttpException(Craft::t('microscope', 'Scan not found.'));
        }

        $previous = $plugin->scans->getPreviousScan($scanId);
        $previousResult = $previous !== null ? $plugin->scans->getResultForScan((int)$previous->id) : null;

        return $this->renderTemplate('microscope/scans/detail', [
            'title' => Craft::t('microscope', 'Scan #{id}', ['id' => $scan->id]),
            'scan' => $scan,
            'result' => $scan->result,
            'previous' => $previous,
            'newFindings' => $scan->result?->newSince($previousResult) ?? [],
            'resolvedFindings' => $scan->result?->resolvedSince($previousResult) ?? [],
            'categories' => Category::all(),
            'checks' => $plugin->checks,
            'pdfAvailable' => $plugin->reports->pdfIsAvailable(),
            'selectedSubnavItem' => 'scans',
        ]);
    }

    /**
     * Queues a scan.
     *
     * Always queued, never run inline. A full scan reads every template on the site and issues a
     * dozen diagnostic queries — on a large site that comfortably outlives a web request, and the
     * one thing a performance plugin must not do is time out the control panel.
     */
    public function actionRun(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SCAN);

        $request = $this->request;
        $config = new ScanConfig();
        $config->trigger = ScanConfig::TRIGGER_MANUAL;

        $categories = $request->getBodyParam('categories');

        if (is_array($categories) && $categories !== []) {
            $config->categories = array_values(array_filter($categories, [Category::class, 'exists']));
        }

        Craft::$app->getQueue()->push(new ScanJob([
            'config' => $config->toArray(),
            'creatorId' => Craft::$app->getUser()->getId(),
            'notify' => false,
        ]));

        $this->setSuccessFlash(Craft::t('microscope', 'Scan started. It’ll appear here when it finishes.'));

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $scanId = (int)$this->request->getRequiredBodyParam('scanId');

        if (!Plugin::getInstance()->scans->deleteScan($scanId)) {
            $this->setFailFlash(Craft::t('microscope', 'Couldn’t delete that scan.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('microscope', 'Scan deleted.'));

        return $this->redirect('microscope/scans');
    }

    /**
     * Whether a scan has finished, for the "scanning…" state to poll.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $plugin = Plugin::getInstance();
        $latest = $plugin->scans->getLatestScan();
        $since = (int)$this->request->getParam('since', 0);

        return $this->asJson([
            'finished' => $latest !== null && $latest->id > $since,
            'scanId' => $latest?->id,
            'score' => $latest?->score,
            'url' => $latest !== null ? \craft\helpers\UrlHelper::cpUrl('microscope/scans/' . $latest->id) : null,
        ]);
    }

    /**
     * Sends the report for a scan to the configured recipients, on demand.
     */
    public function actionEmail(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $scanId = (int)$this->request->getRequiredBodyParam('scanId');
        $scan = $plugin->scans->getScanWithResult($scanId);

        if ($scan === null) {
            $this->setFailFlash(Craft::t('microscope', 'Scan not found.'));

            return null;
        }

        $recipients = $plugin->getSettings()->resolvedRecipients();

        if ($recipients === []) {
            $this->setFailFlash(Craft::t('microscope', 'No notification recipients are configured.'));

            return null;
        }

        try {
            $sent = $plugin->reports->email($scan, $recipients);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), Plugin::LOG_CATEGORY);
            $this->setFailFlash(Craft::t('microscope', 'Couldn’t send the report: {message}', [
                'message' => $e->getMessage(),
            ]));

            return null;
        }

        if ($sent) {
            $this->setSuccessFlash(Craft::t('microscope', 'Report sent to {count} recipients.', [
                'count' => count($recipients),
            ]));
        } else {
            $this->setFailFlash(Craft::t('microscope', 'The report couldn’t be sent. Check Craft’s email settings.'));
        }

        return $this->redirectToPostedUrl();
    }
}
