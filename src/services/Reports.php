<?php

namespace justinholtweb\microscope\services;

use Craft;
use craft\helpers\FileHelper;
use craft\web\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Turns a stored scan into something somebody can read away from the control panel.
 *
 * Two outputs, from one template:
 *
 * - **A print-optimised page**, which the browser saves as a PDF with better typography than any
 *   library produces, and which is the right answer for someone reading it now.
 * - **A real PDF file**, rendered server-side by dompdf, which is the right answer for emailing,
 *   attaching to a scheduled scan, or archiving.
 *
 * Both render `report/document.twig` in the *site* template mode with its CSS inlined. That last
 * part is not incidental: rendering in CP mode lets every other installed plugin inject its
 * toolbars and banners into `head()`/`endBody()`, and they all end up in the PDF.
 */
class Reports extends Component
{
    /**
     * Renders the report as a standalone HTML document.
     *
     * @param bool $forPdf Slightly different CSS — dompdf has no flexbox or grid worth the name,
     *                     so the layout falls back to tables for it.
     */
    public function renderHtml(Scan $scan, bool $forPdf = false): string
    {
        $plugin = Plugin::getInstance();
        $result = $scan->result ?? $plugin->scans->getResultForScan((int)$scan->id);
        $previous = $scan->id !== null ? $plugin->scans->getPreviousScan($scan->id) : null;
        $previousResult = $previous !== null
            ? $plugin->scans->getResultForScan((int)$previous->id)
            : null;

        $view = Craft::$app->getView();

        // Rendered as a site template so that no control panel asset bundle, plugin banner or
        // Live Preview shim can inject itself into the document.
        return $view->renderTemplate(
            'microscope/report/document',
            [
                'scan' => $scan,
                'result' => $result,
                'previous' => $previous,
                'newFindings' => $result->newSince($previousResult),
                'resolvedFindings' => $result->resolvedSince($previousResult),
                'categories' => Category::all(),
                'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
                'siteUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
                'generatedAt' => new \DateTime(),
                'forPdf' => $forPdf,
                'css' => $this->styles($forPdf),
            ],
            View::TEMPLATE_MODE_CP,
        );
    }

    /**
     * Renders the report as a PDF and returns its bytes.
     *
     * @throws \RuntimeException if dompdf isn't installed.
     */
    public function renderPdf(Scan $scan): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException(Craft::t(
                'microscope',
                'PDF output needs the dompdf library. Run `composer require dompdf/dompdf`, or use the printable report instead.',
            ));
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', Craft::$app->getPath()->getTempPath());

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->renderHtml($scan, true), 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();

        // An empty document means the render produced nothing — better to say so than to hand
        // back a zero-byte file that a mail client will happily attach.
        if ($output === '') {
            throw new \RuntimeException(Craft::t('microscope', 'The PDF could not be generated.'));
        }

        return $output;
    }

    /**
     * Writes the PDF to a temporary file and returns its path.
     *
     * Used by the notification mailer, which needs a file on disk to attach.
     */
    public function writePdf(Scan $scan): string
    {
        $directory = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'microscope';
        FileHelper::createDirectory($directory);

        $path = $directory . DIRECTORY_SEPARATOR . $this->filename($scan);
        file_put_contents($path, $this->renderPdf($scan));

        return $path;
    }

    /**
     * A filename that sorts chronologically and says which site it came from.
     */
    public function filename(Scan $scan): string
    {
        $site = FileHelper::sanitizeFilename(
            Craft::$app->getSites()->getPrimarySite()->getName(),
            ['asciiOnly' => true, 'separator' => '-'],
        );

        $date = ($scan->dateCreated ?? new \DateTime())->format('Y-m-d');

        return sprintf('%s-performance-%s.pdf', strtolower($site), $date);
    }

    public function pdfIsAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    /**
     * The report stylesheet, inlined into the document.
     *
     * Inlined rather than registered as an asset bundle for the reason above — and because a PDF
     * renderer has no way to fetch an external stylesheet in the first place.
     */
    private function styles(bool $forPdf): string
    {
        $path = __DIR__ . '/../web/assets/report/dist/report.css';
        $css = @file_get_contents($path);

        if ($css === false) {
            return '';
        }

        if (!$forPdf) {
            return $css;
        }

        $pdfPath = __DIR__ . '/../web/assets/report/dist/report-pdf.css';
        $pdfCss = @file_get_contents($pdfPath);

        return $css . ($pdfCss === false ? '' : "\n" . $pdfCss);
    }

    /**
     * Sends the report to the configured recipients.
     *
     * @param string[] $recipients
     */
    public function email(Scan $scan, array $recipients, ?string $subject = null): bool
    {
        if ($recipients === []) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $siteName = Craft::$app->getSites()->getPrimarySite()->getName();

        $subject ??= Craft::t('microscope', '{site} performance report — score {score}/100', [
            'site' => $siteName,
            'score' => $scan->score ?? 0,
        ]);

        $message = Craft::$app->getMailer()
            ->compose()
            ->setTo($recipients)
            ->setSubject($subject)
            ->setHtmlBody($this->renderEmailBody($scan));

        if ($settings->attachPdf && $this->pdfIsAvailable()) {
            try {
                $message->attach($this->writePdf($scan), ['fileName' => $this->filename($scan)]);
            } catch (Throwable $e) {
                // A failed attachment must not cost the recipient the email — the summary in the
                // body is the part that matters, and the report is a click away in the CP.
                Craft::warning(
                    sprintf('Could not attach the PDF to scan %d’s email: %s', $scan->id, $e->getMessage()),
                    Plugin::LOG_CATEGORY,
                );
            }
        }

        return $message->send();
    }

    private function renderEmailBody(Scan $scan): string
    {
        $plugin = Plugin::getInstance();
        $result = $scan->result ?? $plugin->scans->getResultForScan((int)$scan->id);
        $previous = $scan->id !== null ? $plugin->scans->getPreviousScan($scan->id) : null;
        $previousResult = $previous !== null ? $plugin->scans->getResultForScan((int)$previous->id) : null;

        return Craft::$app->getView()->renderTemplate(
            'microscope/report/email',
            [
                'scan' => $scan,
                'result' => $result,
                'previous' => $previous,
                'newFindings' => $result->newSince($previousResult),
                'resolvedFindings' => $result->resolvedSince($previousResult),
                'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
                'cpUrl' => \craft\helpers\UrlHelper::cpUrl('microscope/scans/' . $scan->id),
            ],
            View::TEMPLATE_MODE_CP,
        );
    }
}
