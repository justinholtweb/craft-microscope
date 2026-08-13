<?php

namespace justinholtweb\microscope\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Microscope from the command line.
 *
 * The console is where scheduled scans belong, and it's also where a scan belongs in CI — hence
 * `--fail-on`, which gives the command a non-zero exit status when the site is worse than a
 * threshold. A deploy pipeline can then refuse to ship a change that turns OPcache off.
 *
 * One caveat, stated here because it catches people out: a scan run from the CLI reads the *CLI*
 * PHP configuration. `memory_limit`, `max_execution_time` and OPcache are all commonly different
 * there. Every scan records which SAPI it came from, and the PHP findings say so — but for the
 * PHP category specifically, a scan from the control panel describes the site more accurately.
 */
class ScanController extends Controller
{
    public $defaultAction = 'run';

    /**
     * @var string|null Restrict the scan to one category: php, database, server, craft, schema or
     *                  templates.
     */
    public ?string $category = null;

    /**
     * @var string|null Exit non-zero if any finding is at or above this severity: critical,
     *                  warning or notice.
     */
    public ?string $failOn = null;

    /**
     * @var bool Show every finding's remediation, not just its headline.
     */
    public bool $verbose = false;

    /**
     * @var string|null Write the report to this path as a PDF.
     */
    public ?string $pdf = null;

    /**
     * @var bool Send the configured notification email.
     */
    public bool $notify = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'run' => array_merge($options, ['category', 'failOn', 'verbose', 'pdf', 'notify']),
            'report' => array_merge($options, ['pdf']),
            default => $options,
        };
    }

    public function optionAliases(): array
    {
        return [
            'c' => 'category',
            'v' => 'verbose',
        ];
    }

    /**
     * Runs a scan and prints the result.
     */
    public function actionRun(): int
    {
        $config = new ScanConfig();
        $config->trigger = ScanConfig::TRIGGER_CONSOLE;

        if ($this->category !== null) {
            if (!Category::exists($this->category)) {
                $this->stderr(sprintf(
                    "Unknown category “%s”. Valid categories: %s\n",
                    $this->category,
                    implode(', ', Category::all()),
                ), Console::FG_RED);

                return ExitCode::USAGE;
            }

            $config->categories = [$this->category];
        }

        if ($this->failOn !== null && !in_array($this->failOn, Finding::SEVERITIES, true)) {
            $this->stderr(sprintf(
                "Unknown severity “%s”. Valid severities: %s\n",
                $this->failOn,
                implode(', ', Finding::SEVERITIES),
            ), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $plugin = Plugin::getInstance();

        $this->stdout("Scanning…\n\n", Console::FG_GREY);

        try {
            $scan = $plugin->scans->run($config, function(string $message) {
                $this->stdout('  ' . $message . "\n", Console::FG_GREY);
            });
        } catch (Throwable $e) {
            $this->stderr("\nThe scan failed: " . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::SOFTWARE;
        }

        $result = $scan->result ?? $plugin->scans->getResultForScan((int)$scan->id);

        $this->printSummary($scan, $result);
        $this->printFindings($result);

        if ($this->pdf !== null) {
            $this->writePdf($scan);
        }

        if ($this->notify) {
            $sent = $plugin->schedules->notify($scan);
            $this->stdout($sent ? "\nNotification sent.\n" : "\nNo notification was sent (check the notification settings).\n", Console::FG_GREY);
        }

        return $this->exitCodeFor($result);
    }

    /**
     * Runs a scan only if the schedule says one is due. Intended for cron.
     *
     * Put this on a schedule a little finer than the scan frequency itself — hourly is right for a
     * daily or weekly scan — and it will start one when, and only when, it's time:
     *
     *     0 * * * * cd /path/to/site && php craft microscope/scan/scheduled
     */
    public function actionScheduled(): int
    {
        $plugin = Plugin::getInstance();
        $schedules = $plugin->schedules;

        if (!$plugin->getSettings()->scheduleEnabled) {
            $this->stdout("Scheduled scans are turned off.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        if (!$schedules->isDue()) {
            $next = $schedules->nextRunAt();

            $this->stdout(sprintf(
                "Not due yet. Next scan: %s\n",
                $next?->format('Y-m-d H:i') ?? 'unknown',
            ), Console::FG_GREY);

            return ExitCode::OK;
        }

        // Runs inline rather than queueing: cron is already a background context, and running it
        // here means the exit status reflects the scan rather than the act of scheduling one.
        $config = new ScanConfig();
        $config->trigger = ScanConfig::TRIGGER_SCHEDULED;

        try {
            $scan = $plugin->scans->run($config);
        } catch (Throwable $e) {
            $this->stderr('The scheduled scan failed: ' . $e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::SOFTWARE;
        }

        $schedules->notify($scan);

        $this->stdout(sprintf(
            "Scan #%d complete — score %d/100, %d problems.\n",
            $scan->id,
            $scan->score ?? 0,
            $scan->problemCount(),
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists recent scans.
     */
    public function actionList(): int
    {
        $scans = Plugin::getInstance()->scans->getScans(20);

        if ($scans === []) {
            $this->stdout("No scans yet. Run `craft microscope/scan/run`.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-6s %-20s %-12s %-7s %s\n", 'ID', 'DATE', 'TRIGGER', 'SCORE', 'FINDINGS'), Console::BOLD);

        foreach ($scans as $scan) {
            $this->stdout(sprintf(
                "%-6s %-20s %-12s %-7s %d critical, %d warnings, %d notices\n",
                '#' . $scan->id,
                $scan->dateCreated?->format('Y-m-d H:i') ?? '—',
                $scan->trigger,
                $scan->score !== null ? $scan->score . '/100' : $scan->status,
                $scan->criticalCount,
                $scan->warningCount,
                $scan->noticeCount,
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Writes an existing scan's report to a PDF.
     *
     * @param int|null $id The scan to report on. Defaults to the most recent.
     */
    public function actionReport(?int $id = null): int
    {
        $plugin = Plugin::getInstance();

        $scan = $id !== null
            ? $plugin->scans->getScanWithResult($id)
            : $plugin->scans->getLatestScan();

        if ($scan === null) {
            $this->stderr("No scan found.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->pdf ??= getcwd() . DIRECTORY_SEPARATOR . $plugin->reports->filename($scan);

        return $this->writePdf($scan) ? ExitCode::OK : ExitCode::SOFTWARE;
    }

    /**
     * Deletes old scans, keeping the configured number.
     *
     * @param int|null $keep How many to keep. Defaults to the retention setting.
     */
    public function actionPrune(?int $keep = null): int
    {
        $deleted = Plugin::getInstance()->scans->prune($keep);

        $this->stdout(sprintf("Deleted %d scans.\n", $deleted), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists every registered check and whether it's enabled.
     */
    public function actionChecks(): int
    {
        $checks = Plugin::getInstance()->checks;

        foreach ($checks->getGroupedChecks() as $category => $categoryChecks) {
            $this->stdout("\n" . strtoupper(Category::label($category)) . "\n", Console::BOLD);

            foreach ($categoryChecks as $check) {
                $enabled = $checks->isEnabled($check->handle());

                $this->stdout(sprintf(
                    "  %s %-24s %s\n",
                    $enabled ? '✓' : '·',
                    $check->handle(),
                    $check->label(),
                ), $enabled ? Console::FG_GREEN : Console::FG_GREY);
            }
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    private function writePdf(\justinholtweb\microscope\models\Scan $scan): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->reports->pdfIsAvailable()) {
            $this->stderr("PDF output needs dompdf: composer require dompdf/dompdf\n", Console::FG_RED);

            return false;
        }

        try {
            $pdf = $plugin->reports->renderPdf($scan);
        } catch (Throwable $e) {
            $this->stderr('Could not generate the PDF: ' . $e->getMessage() . "\n", Console::FG_RED);

            return false;
        }

        $path = $this->pdf ?? (getcwd() . DIRECTORY_SEPARATOR . $plugin->reports->filename($scan));

        if (file_put_contents($path, $pdf) === false) {
            $this->stderr(sprintf("Could not write to %s\n", $path), Console::FG_RED);

            return false;
        }

        $this->stdout(sprintf("\nReport written to %s\n", $path), Console::FG_GREEN);

        return true;
    }

    private function printSummary(\justinholtweb\microscope\models\Scan $scan, \justinholtweb\microscope\models\ScanResult $result): void
    {
        $score = $scan->score ?? 0;
        $colour = match (true) {
            $score >= 90 => Console::FG_GREEN,
            $score >= 70 => Console::FG_YELLOW,
            default => Console::FG_RED,
        };

        $this->stdout("\n");
        $this->stdout(sprintf("  Score: %d/100 (%s)\n", $score, $result->grade()), $colour, Console::BOLD);
        $this->stdout(sprintf("  %s\n", $result->headline()), Console::FG_GREY);
        $this->stdout(sprintf("  Scan #%d, %.1fs\n\n", $scan->id, $result->runtime));

        foreach ($result->categoryScores() as $category => $categoryScore) {
            $this->stdout(sprintf(
                "    %-22s %3d/100\n",
                Category::label($category),
                $categoryScore,
            ), $categoryScore >= 90 ? Console::FG_GREEN : ($categoryScore >= 70 ? Console::FG_YELLOW : Console::FG_RED));
        }

        if ($result->skipped !== []) {
            $this->stdout(sprintf("\n  %d checks were skipped:\n", count($result->skipped)), Console::FG_GREY);

            foreach ($result->skipped as $handle => $reason) {
                $this->stdout(sprintf("    %s — %s\n", $handle, $reason), Console::FG_GREY);
            }
        }
    }

    private function printFindings(\justinholtweb\microscope\models\ScanResult $result): void
    {
        $problems = $result->problems();

        if ($problems === []) {
            $this->stdout("\n  Nothing to fix.\n\n", Console::FG_GREEN);

            return;
        }

        $this->stdout("\n");

        foreach ($problems as $finding) {
            $colour = match ($finding->severity) {
                Finding::SEVERITY_CRITICAL => Console::FG_RED,
                Finding::SEVERITY_WARNING => Console::FG_YELLOW,
                default => Console::FG_GREY,
            };

            $this->stdout(sprintf("  [%s] ", strtoupper($finding->severity)), $colour, Console::BOLD);
            $this->stdout($finding->title . "\n");
            $this->stdout('        ' . $finding->summary . "\n", Console::FG_GREY);

            if ($finding->observed !== null) {
                $this->stdout(sprintf(
                    "        Found: %s%s\n",
                    $finding->observed,
                    $finding->expected !== null ? '   Expected: ' . $finding->expected : '',
                ), Console::FG_GREY);
            }

            if ($this->verbose && $finding->remediation !== '') {
                $this->stdout("\n" . $this->indent($finding->remediation) . "\n", Console::FG_CYAN);
            }

            $this->stdout("\n");
        }

        if (!$this->verbose) {
            $this->stdout("  Re-run with --verbose to see how to fix each of these.\n\n", Console::FG_GREY);
        }
    }

    private function indent(string $text, string $prefix = '        '): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, trim($text));
    }

    /**
     * Non-zero when the scan is worse than `--fail-on` allows.
     */
    private function exitCodeFor(\justinholtweb\microscope\models\ScanResult $result): int
    {
        if ($this->failOn === null) {
            return ExitCode::OK;
        }

        $threshold = array_search($this->failOn, Finding::SEVERITIES, true);

        if ($threshold === false) {
            return ExitCode::OK;
        }

        foreach ($result->problems() as $finding) {
            if ($finding->rank() <= $threshold) {
                $this->stderr(sprintf(
                    "Failing: found a %s finding, and --fail-on is set to %s.\n",
                    $finding->severity,
                    $this->failOn,
                ), Console::FG_RED);

                return ExitCode::SOFTWARE;
            }
        }

        return ExitCode::OK;
    }
}
