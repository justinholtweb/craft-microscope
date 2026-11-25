<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Http\Controllers;

use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Reports;
use JustinHoltWeb\Microscope\Services\Scans;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * The report as a printable page and as a PDF.
 */
class ReportsController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly Scans $scans,
        private readonly Reports $reports,
    ) {}

    /**
     * The printable report: a plain HTML response rather than a control panel screen, so nothing
     * else on the site gets to inject anything into it.
     */
    public function print(int $scanId): Response
    {
        return response($this->reports->renderHtml($this->find($scanId)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'none',
        ]);
    }

    public function pdf(int $scanId, Request $request): Response
    {
        $scan = $this->find($scanId);
        $printable = cp_url("microscope/scans/{$scanId}/report");

        if (! $this->reports->pdfIsAvailable()) {
            // Hand over the printable version instead: the browser's own "Save as PDF" gets the
            // reader to the same place.
            Flash::error(t('PDF output isn’t available — the dompdf library isn’t installed. Showing the printable report instead.', category: 'microscope'));

            return redirect($printable);
        }

        try {
            $pdf = $this->reports->renderPdf($scan);
        } catch (Throwable $e) {
            Log::channel(Plugin::LOG_CHANNEL)->error(sprintf('PDF generation failed for scan %d: %s', $scanId, $e->getMessage()));
            Flash::error(t('The PDF couldn’t be generated: {message}', ['message' => $e->getMessage()], category: 'microscope'));

            return redirect($printable);
        }

        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $this->reports->filename($scan)),
        ]);
    }

    private function find(int $scanId): Scan
    {
        return $this->scans->getScanWithResult($scanId) ?? abort(404, t('Scan not found.', category: 'microscope'));
    }
}
