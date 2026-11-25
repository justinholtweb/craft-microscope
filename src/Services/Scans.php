<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Checks\CheckInterface;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Data\ScanResult;
use JustinHoltWeb\Microscope\Database\Tables;
use JustinHoltWeb\Microscope\Events\ScanCompleted;
use JustinHoltWeb\Microscope\Events\ScanFailed;
use JustinHoltWeb\Microscope\Events\ScanStarting;
use JustinHoltWeb\Microscope\Jobs\RunScan;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\ScanFinding;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use RuntimeException;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Running scans, storing them, and reading them back.
 */
class Scans
{
    public function __construct(
        private readonly Checks $checks,
        private readonly Settings $settings,
    ) {}

    /**
     * Runs every applicable check and stores the result.
     *
     * A check that throws is caught and recorded as skipped rather than aborting the scan: a scan
     * is a diagnostic run against an environment that is by definition possibly broken, and one
     * check hitting an unreadable `/proc` file must not cost the site the other thirty.
     *
     * @param  (callable(string, float): void)|null  $progress  Called with a message and a 0–1 fraction.
     */
    public function run(?ScanConfig $config = null, ?callable $progress = null, ?int $creatorId = null): Scan
    {
        $config ??= new ScanConfig;

        event($starting = new ScanStarting($config));

        if (! $starting->isValid) {
            throw new RuntimeException(t('The scan was cancelled by an event listener.', category: 'microscope'));
        }

        $config = $starting->config;

        $scan = Scan::create([
            'status' => Scan::STATUS_RUNNING,
            'trigger' => $config->trigger,
            'config' => $config,
            'creatorId' => $creatorId,
            'dateStarted' => Carbon::now(),
        ]);

        $started = microtime(true);
        $result = new ScanResult;

        try {
            $context = CheckContext::make($this->settings, $config);
            $checks = $this->applicableChecks($config);
            $total = max(count($checks), 1);

            foreach ($checks as $index => $check) {
                if ($progress !== null) {
                    $progress(t('Checking {label}', ['label' => $check->label()], category: 'microscope'), $index / $total);
                }

                $this->runCheck($check, $context, $result);
            }

            $result->environment = $context->summary();
            $result->runtime = microtime(true) - $started;

            if (! $config->includePasses) {
                $result->findings = $result->problems();
            }

            $this->store($scan, $result);
        } catch (Throwable $e) {
            $scan->forceFill([
                'status' => Scan::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 65000),
                'runtime' => microtime(true) - $started,
                'dateFinished' => Carbon::now(),
            ])->save();

            Log::channel(Plugin::LOG_CHANNEL)->error(sprintf('Scan %d failed: %s', $scan->id, $e->getMessage()), ['exception' => $e]);

            event(new ScanFailed($scan, $e));

            throw $e;
        }

        $this->prune();

        $scan->result = $result;

        event(new ScanCompleted($scan, $result, $this->getPreviousScan($scan->id)));

        return $scan;
    }

    /**
     * Queues a scan: what the control panel and MCP do, never scanning inside a request. A full
     * scan on a large site comfortably outlives one, and a performance tool must not time out the
     * control panel.
     */
    public function queueScan(ScanConfig $config, ?int $creatorId = null): void
    {
        RunScan::dispatch($config->toArray(), $creatorId);
    }

    /**
     * Runs one check, containing anything it throws.
     */
    private function runCheck(CheckInterface $check, CheckContext $context, ScanResult $result): void
    {
        try {
            if (! $check->isAvailable($context)) {
                $result->skipped[$check->handle()] = $check->unavailableReason($context);

                return;
            }

            $result->addFindings($check->run($context));
        } catch (Throwable $e) {
            $result->skipped[$check->handle()] = t('The check failed: {message}', ['message' => $e->getMessage()], category: 'microscope');

            Log::channel(Plugin::LOG_CHANNEL)->warning(sprintf('Check "%s" threw: %s', $check->handle(), $e->getMessage()));
        }
    }

    /**
     * The checks this scan should attempt, after settings and the scan's own config have had their say.
     *
     * @return list<CheckInterface>
     */
    public function applicableChecks(ScanConfig $config): array
    {
        return array_values(array_filter(
            $this->checks->all(),
            fn (CheckInterface $check) => $config->coversCategory($check->category())
                && ! in_array($check->handle(), $config->skipChecks, true)
                && $this->settings->checkIsEnabled($check->handle()),
        ));
    }

    /**
     * Writes the result to the scan row and its findings, in one transaction.
     */
    private function store(Scan $scan, ScanResult $result): void
    {
        DB::transaction(function () use ($scan, $result): void {
            $scan->forceFill([
                'status' => Scan::STATUS_COMPLETED,
                'score' => $result->score(),
                'categoryScores' => $result->categoryScores(),
                'environment' => $result->environment,
                'skipped' => $result->skipped,
                'criticalCount' => $result->countBySeverity(Finding::SEVERITY_CRITICAL),
                'warningCount' => $result->countBySeverity(Finding::SEVERITY_WARNING),
                'noticeCount' => $result->countBySeverity(Finding::SEVERITY_NOTICE),
                'passCount' => $result->countBySeverity(Finding::SEVERITY_PASS),
                'runtime' => $result->runtime,
                'dateFinished' => Carbon::now(),
            ])->save();

            foreach ($result->findings as $finding) {
                $scan->findings()->create(ScanFinding::attributesFor($finding));
            }
        });
    }

    // Reading ------------------------------------------------------------------------------------

    public function getScanById(int $id): ?Scan
    {
        return Scan::query()->find($id);
    }

    /** A scan with its findings loaded. */
    public function getScanWithResult(int $id): ?Scan
    {
        $scan = $this->getScanById($id);

        return $scan === null ? null : $this->withResult($scan);
    }

    public function withResult(Scan $scan): Scan
    {
        $scan->result ??= $this->getResultForScan($scan);

        return $scan;
    }

    public function getResultForScan(Scan|int $scan): ScanResult
    {
        $scan = $scan instanceof Scan ? $scan : $this->getScanById($scan);
        $result = new ScanResult;

        if ($scan === null) {
            return $result;
        }

        foreach (ScanFinding::query()->where('scanId', $scan->id)->orderBy('id')->get() as $row) {
            $result->addFinding($row->toFinding());
        }

        $result->environment = $scan->environment ?? [];
        $result->skipped = $scan->skipped ?? [];
        $result->runtime = $scan->runtime ?? 0.0;

        return $result;
    }

    /** The most recent completed scan. */
    public function getLatestScan(): ?Scan
    {
        return Scan::query()->completed()->newestFirst()->first();
    }

    /** The completed scan before a given one — what "new since last time" is measured against. */
    public function getPreviousScan(Scan|int $scan): ?Scan
    {
        $id = $scan instanceof Scan ? $scan->id : $scan;

        return Scan::query()->completed()->where('id', '<', $id)->orderByDesc('id')->first();
    }

    /**
     * The scan's findings compared with the previous completed scan's.
     *
     * @return array{previous: ?Scan, new: list<Finding>, resolved: list<Finding>}
     */
    public function compareWithPrevious(Scan $scan): array
    {
        $result = $this->withResult($scan)->result ?? new ScanResult;
        $previous = $this->getPreviousScan($scan);
        $previousResult = $previous !== null ? $this->getResultForScan($previous) : null;

        return [
            'previous' => $previous,
            'new' => $result->newSince($previousResult),
            'resolved' => $result->resolvedSince($previousResult),
        ];
    }

    /** @return list<Scan> Newest first. */
    public function getScans(int $limit = 50, int $offset = 0): array
    {
        return array_values(Scan::query()->newestFirst()->offset(max(0, $offset))->limit(max(1, $limit))->get()->all());
    }

    public function getTotalScans(): int
    {
        return Scan::query()->count();
    }

    /**
     * Score history, oldest first, for the dashboard chart.
     *
     * @return list<array{id: int, score: int, date: string}>
     */
    public function getScoreHistory(int $limit = 30): array
    {
        $scans = Scan::query()->completed()->whereNotNull('score')->newestFirst()->limit($limit)->get(['id', 'score', 'dateCreated']);

        return array_reverse(array_values($scans->map(fn (Scan $scan) => [
            'id' => $scan->id,
            'score' => (int) $scan->score,
            'date' => $scan->dateCreated->toAtomString(),
        ])->all()));
    }

    /** The last time a scan ran with the given trigger — how the scheduler knows whether one is due. */
    public function getLastRunAt(string $trigger): ?Carbon
    {
        $scan = Scan::query()->where('trigger', $trigger)->newestFirst()->first(['id', 'dateCreated']);

        return $scan?->dateCreated;
    }

    /** Whether a scan is running right now (or was left `running` by a crashed worker in the last hour). */
    public function isRunning(): bool
    {
        return Scan::query()->where('status', Scan::STATUS_RUNNING)->where('dateCreated', '>=', Carbon::now()->subHour())->exists();
    }

    public function deleteScan(int $id): bool
    {
        // Findings go with it through the foreign key's ON DELETE CASCADE; deleted explicitly too,
        // for SQLite connections that don't enforce foreign keys.
        return DB::transaction(function () use ($id): bool {
            ScanFinding::query()->where('scanId', $id)->delete();

            return Scan::query()->whereKey($id)->delete() > 0;
        });
    }

    /**
     * Drops scans beyond the retention limit. By ID rather than by date, so two scans recorded in
     * the same second can't survive each other's pruning.
     */
    public function prune(?int $keep = null): int
    {
        $keep = max(1, $keep ?? $this->settings->retainScans);

        $cutoff = DB::table(Tables::SCANS)->orderByDesc('id')->offset($keep - 1)->limit(1)->value('id');

        if ($cutoff === null) {
            return 0;
        }

        return DB::transaction(function () use ($cutoff): int {
            DB::table(Tables::FINDINGS)->where('scanId', '<', (int) $cutoff)->delete();

            return DB::table(Tables::SCANS)->where('id', '<', (int) $cutoff)->delete();
        });
    }
}
