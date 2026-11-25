<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Data\ScanResult;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Services\Notifier;
use JustinHoltWeb\Microscope\Services\Reports;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Support\Presenter;
use Throwable;

/**
 * Runs a scan now and prints the result. `--fail-on` makes it a CI gate.
 *
 * Runs under the CLI's php.ini, so the memory, request-limit and realpath checks are skipped (with
 * the reason) rather than reporting the console binary's values as the site's.
 */
class ScanRunCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:scan:run
        {--c|category= : Only scan one area (php, database, server, craft, schema, templates)}
        {--fail-on= : Exit non-zero when a finding of this severity or worse is found (critical, warning, notice)}
        {--pdf= : Also write the report as a PDF to this path}
        {--notify : Send the scheduled-scan notification for this scan}
        {--queue : Queue the scan instead of running it here}
        {--json : Print the scan as JSON (the MCP microscope_scan_get shape)}';

    #[\Override]
    protected $description = 'Scans the site for performance problems. Add -v to see how to fix each one.';

    /** The Craft 5 command and its default action. */
    #[\Override]
    protected $aliases = ['microscope/scan/run', 'microscope/scan'];

    public function handle(Scans $scans, Reports $reports, Notifier $notifier): int
    {
        $categories = [];

        if (($category = $this->option('category')) !== null) {
            if (! Category::exists((string) $category)) {
                $this->error(sprintf('Unknown category “%s”. Valid categories: %s', $category, implode(', ', Category::all())));

                return self::INVALID;
            }

            $categories = [(string) $category];
        }

        $failOn = $this->option('fail-on');

        if ($failOn !== null && ! in_array($failOn, Finding::SEVERITIES, true)) {
            $this->error(sprintf('Unknown severity “%s”. Valid severities: %s', $failOn, implode(', ', Finding::SEVERITIES)));

            return self::INVALID;
        }

        $config = new ScanConfig(categories: $categories, trigger: ScanConfig::TRIGGER_CONSOLE);

        if ($this->option('queue')) {
            $scans->queueScan($config);
            $this->info('Scan queued.');

            return self::SUCCESS;
        }

        $json = (bool) $this->option('json');

        if (! $json) {
            $this->line('<fg=gray>Scanning…</>');
        }

        try {
            $scan = $scans->run($config, function (string $message) use ($json): void {
                if (! $json && $this->output->isVerbose()) {
                    $this->line("<fg=gray>  {$message}</>");
                }
            });
        } catch (Throwable $e) {
            $this->error('The scan failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $result = $scan->result ?? $scans->getResultForScan($scan);

        if ($json) {
            $comparison = $scans->compareWithPrevious($scan);
            $this->line((string) json_encode([
                'scan' => Presenter::scan($scan, $scan->cpUrl()),
                'newCount' => count($comparison['new']),
                'findings' => Presenter::findings($result, Presenter::fingerprints($comparison['new']), includeEvidence: true),
                'resolved' => array_map(Presenter::resolved(...), $comparison['resolved']),
                'skipped' => Presenter::skipped($result->skipped),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printSummary($scan, $result);
            $this->printFindings($result);
        }

        if (($path = $this->option('pdf')) !== null) {
            ReportPdf::write($this, $reports, $scan, (string) $path);
        }

        if ($this->option('notify')) {
            $sent = $notifier->scheduledScanFinished($scan);
            $this->line($sent ? 'Notification sent.' : '<fg=gray>No notification was sent (check the notification settings).</>');
        }

        return $this->exitCodeFor($result, $failOn);
    }

    private function printSummary(Scan $scan, ScanResult $result): void
    {
        $score = $scan->score ?? 0;
        $colour = $score >= 90 ? 'green' : ($score >= 70 ? 'yellow' : 'red');

        $this->newLine();
        $this->line(sprintf('  <fg=%s;options=bold>Score: %d/100 (%s)</>', $colour, $score, $result->grade()));
        $this->line('  <fg=gray>'.$result->headline().'</>');
        $this->line(sprintf('  Scan #%d, %.1fs', $scan->id, $result->runtime));
        $this->newLine();

        foreach ($result->categoryScores() as $category => $categoryScore) {
            $colour = $categoryScore >= 90 ? 'green' : ($categoryScore >= 70 ? 'yellow' : 'red');
            $this->line(sprintf('    <fg=%s>%-22s %3d/100</>', $colour, Category::label($category), $categoryScore));
        }

        if ($result->skipped !== []) {
            $this->newLine();
            $this->line(sprintf('  <fg=gray>%d checks were skipped:</>', count($result->skipped)));

            foreach ($result->skipped as $handle => $reason) {
                $this->line(sprintf('    <fg=gray>%s — %s</>', $handle, $reason));
            }
        }
    }

    private function printFindings(ScanResult $result): void
    {
        $problems = $result->problems();

        $this->newLine();

        if ($problems === []) {
            $this->info('  Nothing to fix.');

            return;
        }

        foreach ($problems as $finding) {
            $colour = match ($finding->severity) {
                Finding::SEVERITY_CRITICAL => 'red',
                Finding::SEVERITY_WARNING => 'yellow',
                default => 'gray',
            };

            $this->line(sprintf('  <fg=%s;options=bold>[%s]</> %s', $colour, strtoupper($finding->severity), $finding->title));
            $this->line('        <fg=gray>'.$finding->summary.'</>');

            if ($finding->observed !== null) {
                $this->line(sprintf('        <fg=gray>Found: %s%s</>', $finding->observed, $finding->expected !== null ? '   Expected: '.$finding->expected : ''));
            }

            if ($this->output->isVerbose() && $finding->remediation !== '') {
                $this->newLine();
                $this->line('<fg=cyan>        '.str_replace("\n", "\n        ", trim($finding->remediation)).'</>');
            }

            $this->newLine();
        }

        if (! $this->output->isVerbose()) {
            $this->line('  <fg=gray>Re-run with -v to see how to fix each of these.</>');
        }
    }

    private function exitCodeFor(ScanResult $result, ?string $failOn): int
    {
        $threshold = $failOn !== null ? array_search($failOn, Finding::SEVERITIES, true) : false;

        if ($threshold === false) {
            return self::SUCCESS;
        }

        foreach ($result->problems() as $finding) {
            if ($finding->rank() <= $threshold) {
                $this->error(sprintf('Failing: found a %s finding, and --fail-on is set to %s.', $finding->severity, $failOn));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
