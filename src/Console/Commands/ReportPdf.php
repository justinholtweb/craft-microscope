<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Services\Reports;
use Throwable;

/**
 * Writing a scan's PDF from a command, shared by `microscope:scan:run --pdf` and `microscope:scan:report`.
 */
final class ReportPdf
{
    public static function write(Command $command, Reports $reports, Scan $scan, string $path): bool
    {
        if (! $reports->pdfIsAvailable()) {
            $command->error('PDF output needs dompdf: composer require dompdf/dompdf');

            return false;
        }

        try {
            $pdf = $reports->renderPdf($scan);
        } catch (Throwable $e) {
            $command->error('Could not generate the PDF: '.$e->getMessage());

            return false;
        }

        if (@file_put_contents($path, $pdf) === false) {
            $command->error(sprintf('Could not write to %s', $path));

            return false;
        }

        $command->info(sprintf('Report written to %s', $path));

        return true;
    }
}
