<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Services\Reports;
use JustinHoltWeb\Microscope\Services\Scans;

/**
 * Writes a scan's report as a PDF.
 */
class ScanReportCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:scan:report {id? : The scan ID (default: the latest completed scan)} {--pdf= : Where to write it}';

    #[\Override]
    protected $description = 'Writes a Microscope scan’s report as a PDF.';

    #[\Override]
    protected $aliases = ['microscope/scan/report'];

    public function handle(Scans $scans, Reports $reports): int
    {
        $id = $this->argument('id');
        $scan = $id !== null ? $scans->getScanWithResult((int) $id) : $scans->getLatestScan();

        if ($scan === null) {
            $this->error('No scan found.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('pdf') ?? getcwd().DIRECTORY_SEPARATOR.$reports->filename($scan));

        return ReportPdf::write($this, $reports, $scan, $path) ? self::SUCCESS : self::FAILURE;
    }
}
