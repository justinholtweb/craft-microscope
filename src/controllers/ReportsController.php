<?php

namespace justinholtweb\microscope\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\microscope\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The two report outputs.
 */
class ReportsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    /**
     * The print-optimised report.
     *
     * Rendered as a plain response rather than a control panel one, so nothing else on the site
     * gets to inject anything into it.
     *
     * @throws NotFoundHttpException
     */
    public function actionPrint(int $scanId): Response
    {
        $scan = $this->findScan($scanId);

        $this->response->format = Response::FORMAT_HTML;
        $this->response->data = Plugin::getInstance()->reports->renderHtml($scan);

        return $this->response;
    }

    /**
     * The report as a downloadable PDF.
     *
     * @throws NotFoundHttpException
     */
    public function actionPdf(int $scanId): Response
    {
        $scan = $this->findScan($scanId);
        $reports = Plugin::getInstance()->reports;

        if (!$reports->pdfIsAvailable()) {
            // Rather than erroring, hand over the printable version — the browser's own
            // "Save as PDF" gets the reader to the same place.
            Craft::$app->getSession()->setError(Craft::t(
                'microscope',
                'PDF output isn’t available — the dompdf library isn’t installed. Showing the printable report instead.',
            ));

            return $this->redirect("microscope/scans/{$scanId}/report");
        }

        try {
            $pdf = $reports->renderPdf($scan);
        } catch (Throwable $e) {
            Craft::error(
                sprintf('PDF generation failed for scan %d: %s', $scanId, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            Craft::$app->getSession()->setError(Craft::t('microscope', 'The PDF couldn’t be generated: {message}', [
                'message' => $e->getMessage(),
            ]));

            return $this->redirect("microscope/scans/{$scanId}/report");
        }

        return $this->response->sendContentAsFile(
            $pdf,
            $reports->filename($scan),
            ['mimeType' => 'application/pdf', 'inline' => (bool)$this->request->getParam('inline')],
        );
    }

    /**
     * @throws NotFoundHttpException
     */
    private function findScan(int $scanId): \justinholtweb\microscope\models\Scan
    {
        $scan = Plugin::getInstance()->scans->getScanWithResult($scanId);

        if ($scan === null) {
            throw new NotFoundHttpException(Craft::t('microscope', 'Scan not found.'));
        }

        return $scan;
    }
}
