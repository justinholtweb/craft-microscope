<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use Carbon\Carbon;
use CraftCms\Cms\Support\Facades\Path;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\File;
use CraftCms\Cms\View\TemplateMode;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Mail\ScanReportMail;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use RuntimeException;
use Throwable;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Turning a scan into something to hand somebody: a printable page, a PDF, an email.
 */
class Reports
{
    public function __construct(
        private readonly Scans $scans,
        private readonly Settings $settings,
    ) {}

    /**
     * The full report as a standalone HTML document, with its styles inlined.
     */
    public function renderHtml(Scan $scan, bool $forPdf = false): string
    {
        $scan = $this->scans->withResult($scan);
        $comparison = $this->scans->compareWithPrevious($scan);
        $site = Sites::getPrimarySite();

        return template('microscope/report/document', [
            'scan' => $scan,
            'result' => $scan->result,
            'previous' => $comparison['previous'],
            'newFindings' => $comparison['new'],
            'resolvedFindings' => $comparison['resolved'],
            'categories' => Category::all(),
            'siteName' => $site->getName(),
            'siteUrl' => $site->getBaseUrl(),
            'generatedAt' => Carbon::now(),
            'forPdf' => $forPdf,
            'css' => $this->styles($forPdf),
        ], TemplateMode::Cp);
    }

    public function pdfIsAvailable(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function renderPdf(Scan $scan): string
    {
        if (! $this->pdfIsAvailable()) {
            throw new RuntimeException(t('PDF output needs the dompdf library. Run `composer require dompdf/dompdf`, or use the printable report instead.', category: 'microscope'));
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', Path::temp());

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->renderHtml($scan, true), 'UTF-8');
        $dompdf->render();

        $output = (string) $dompdf->output();

        // Better to say so than hand back a zero-byte file a mail client will happily attach.
        if ($output === '') {
            throw new RuntimeException(t('The PDF could not be generated.', category: 'microscope'));
        }

        return $output;
    }

    /** Writes the PDF to Craft's temp directory and returns its path. */
    public function writePdf(Scan $scan): string
    {
        $path = Path::temp('microscope').DIRECTORY_SEPARATOR.$this->filename($scan);
        file_put_contents($path, $this->renderPdf($scan));

        return $path;
    }

    public function filename(Scan $scan): string
    {
        $site = File::sanitizeFilename(Sites::getPrimarySite()->getName(), ['asciiOnly' => true, 'separator' => '-']);

        return sprintf('%s-performance-%s.pdf', strtolower($site), $scan->dateCreated->format('Y-m-d'));
    }

    public function defaultSubject(Scan $scan): string
    {
        return t('{site} performance report — score {score}/100', [
            'site' => Sites::getPrimarySite()->getName(),
            'score' => $scan->score ?? 0,
        ], category: 'microscope');
    }

    /**
     * Emails the report to each recipient, with the PDF attached where settings ask for it and
     * dompdf is installed. Returns how many messages were sent.
     *
     * @param  list<string>  $recipients
     */
    public function email(Scan $scan, array $recipients, ?string $subject = null): int
    {
        if ($recipients === []) {
            return 0;
        }

        $scan = $this->scans->withResult($scan);
        $subject ??= $this->defaultSubject($scan);
        [$html, $text] = $this->emailBodies($scan);
        $pdfPath = null;

        if ($this->settings->attachPdf && $this->pdfIsAvailable()) {
            try {
                $pdfPath = $this->writePdf($scan);
            } catch (Throwable $e) {
                // A failed attachment mustn't cost anyone the email: the summary is the part that
                // matters, and the report is a click away in the control panel.
                Log::channel(Plugin::LOG_CHANNEL)->warning(sprintf('Could not attach the PDF to scan %d’s email: %s', $scan->id, $e->getMessage()));
            }
        }

        $sent = 0;

        try {
            foreach ($recipients as $recipient) {
                // A fresh mailable per recipient: to() appends.
                Mail::to($recipient)->send(new ScanReportMail($subject, $html, $text, $pdfPath, $this->filename($scan)));
                $sent++;
            }
        } finally {
            if ($pdfPath !== null) {
                @unlink($pdfPath);
            }
        }

        return $sent;
    }

    /**
     * @return array{0: string, 1: string} The HTML and plain-text bodies.
     */
    private function emailBodies(Scan $scan): array
    {
        $comparison = $this->scans->compareWithPrevious($scan);
        $siteName = Sites::getPrimarySite()->getName();

        $html = template('microscope/report/email', [
            'scan' => $scan,
            'result' => $scan->result,
            'previous' => $comparison['previous'],
            'newFindings' => $comparison['new'],
            'resolvedFindings' => $comparison['resolved'],
            'siteName' => $siteName,
            'cpUrl' => $scan->cpUrl(),
        ], TemplateMode::Cp);

        $lines = [
            $this->defaultSubject($scan),
            '',
            $scan->result?->headline() ?? '',
        ];

        if ($comparison['new'] !== []) {
            $lines[] = '';
            $lines[] = t('New since the last scan:', category: 'microscope');

            foreach ($comparison['new'] as $finding) {
                $lines[] = sprintf('- [%s] %s', $finding->severity, $finding->title);
            }
        }

        $outstanding = array_filter($scan->result?->problems() ?? [], fn (Finding $f) => $f->severity === Finding::SEVERITY_CRITICAL);

        if ($outstanding !== []) {
            $lines[] = '';
            $lines[] = t('Critical:', category: 'microscope');

            foreach ($outstanding as $finding) {
                $lines[] = '- '.$finding->title;
            }
        }

        $lines[] = '';
        $lines[] = t('Open the full report: {url}', ['url' => $scan->cpUrl()], category: 'microscope');

        return [$html, implode("\n", $lines)."\n"];
    }

    private function styles(bool $forPdf): string
    {
        $css = (string) @file_get_contents(__DIR__.'/../../resources/css/report.css');

        if ($forPdf) {
            $css .= "\n".@file_get_contents(__DIR__.'/../../resources/css/report-pdf.css');
        }

        return $css;
    }
}
