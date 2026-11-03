<?php

namespace justinholtweb\microscope\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\checks\CheckInterface;
use justinholtweb\microscope\events\ScanEvent;
use justinholtweb\microscope\migrations\Install;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\models\ScanResult;
use justinholtweb\microscope\Plugin;
use justinholtweb\microscope\probes\CraftProbe;
use justinholtweb\microscope\probes\DatabaseProbe;
use justinholtweb\microscope\probes\db\CraftSqlRunner;
use justinholtweb\microscope\probes\PhpProbe;
use justinholtweb\microscope\probes\ServerProbe;
use justinholtweb\microscope\probes\ShellRunner;
use justinholtweb\microscope\records\FindingRecord;
use justinholtweb\microscope\records\ScanRecord;
use Throwable;
use yii\base\Component;

/**
 * Running scans, storing them, and reading them back.
 */
class Scans extends Component
{
    /**
     * @event ScanEvent Fired before a scan runs. Cancellable via `$event->isValid = false`.
     */
    public const EVENT_BEFORE_SCAN = 'beforeScan';

    /**
     * @event ScanEvent Fired once a scan has been stored.
     */
    public const EVENT_AFTER_SCAN = 'afterScan';

    /**
     * Runs every applicable check and stores the result.
     *
     * A check that throws is caught and recorded as skipped rather than being allowed to abort the
     * scan. That is the important behaviour here: a scan is a diagnostic run against an
     * environment that is by definition possibly broken, and one check hitting an unreadable
     * `/proc` file must not cost the site the other twenty-eight.
     *
     * @param callable(string, float|null): void|null $progress Called with a message and a 0–1
     *        fraction, so the queue job can report progress.
     */
    public function run(
        ?ScanConfig $config = null,
        ?callable $progress = null,
        ?int $creatorId = null,
    ): Scan {
        $config ??= new ScanConfig();

        $event = new ScanEvent(['config' => $config]);
        $this->trigger(self::EVENT_BEFORE_SCAN, $event);

        if (!$event->isValid) {
            throw new \RuntimeException(Craft::t('microscope', 'The scan was cancelled by an event handler.'));
        }

        $record = new ScanRecord();
        $record->status = ScanRecord::STATUS_RUNNING;
        $record->trigger = $config->trigger;
        $record->config = Db::prepareValueForDb($config->toArray());
        $record->creatorId = $creatorId;
        $record->dateStarted = Db::prepareDateForDb(new \DateTime());
        $record->save(false);

        $started = microtime(true);
        $result = new ScanResult();

        try {
            $context = $this->buildContext($config);
            $checks = $this->applicableChecks($config);
            $total = max(count($checks), 1);

            foreach (array_values($checks) as $index => $check) {
                if ($progress !== null) {
                    $progress(
                        Craft::t('microscope', 'Checking {label}', ['label' => $check->label()]),
                        $index / $total,
                    );
                }

                $this->runCheck($check, $context, $result);
            }

            $result->environment = $this->environment($context);
            $result->runtime = microtime(true) - $started;

            if (!$config->includePasses) {
                $result->findings = $result->problems();
            }

            $this->store($record, $result);
        } catch (Throwable $e) {
            $record->status = ScanRecord::STATUS_FAILED;
            $record->error = $e->getMessage();
            $record->runtime = microtime(true) - $started;
            $record->dateFinished = Db::prepareDateForDb(new \DateTime());
            $record->save(false);

            Craft::error(
                sprintf('Scan %d failed: %s', $record->id, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            throw $e;
        }

        $this->prune();

        $scan = Scan::fromRecord($record);
        $scan->result = $result;

        $this->trigger(self::EVENT_AFTER_SCAN, new ScanEvent([
            'config' => $config,
            'result' => $result,
            'scanId' => $scan->id,
        ]));

        return $scan;
    }

    /**
     * Runs one check, containing anything it throws.
     */
    private function runCheck(CheckInterface $check, CheckContext $context, ScanResult $result): void
    {
        try {
            if (!$check->isAvailable($context)) {
                $result->skipped[$check->handle()] = $check->unavailableReason($context);
                return;
            }

            $result->addFindings($check->run($context));
        } catch (Throwable $e) {
            $result->skipped[$check->handle()] = Craft::t('microscope', 'The check failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            Craft::warning(
                sprintf('Check "%s" threw: %s', $check->handle(), $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );
        }
    }

    /**
     * The checks this scan should attempt, after settings and config have had their say.
     *
     * @return CheckInterface[]
     */
    private function applicableChecks(ScanConfig $config): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return array_values(array_filter(
            Plugin::getInstance()->checks->getAllChecks(),
            function(CheckInterface $check) use ($config, $settings) {
                if (!$config->coversCategory($check->category())) {
                    return false;
                }

                if (in_array($check->handle(), $config->skipChecks, true)) {
                    return false;
                }

                return $settings->checkIsEnabled($check->handle());
            },
        ));
    }

    /**
     * Assembles the probes.
     */
    public function buildContext(?ScanConfig $config = null): CheckContext
    {
        $settings = Plugin::getInstance()->getSettings();

        // Honouring the setting here rather than in each check means there is exactly one place
        // that decides whether Microscope may run a command.
        $shell = new ShellRunner($settings->allowShellProbes);

        return new CheckContext(
            settings: $settings,
            config: $config ?? new ScanConfig(),
            php: new PhpProbe(),
            database: new DatabaseProbe(new CraftSqlRunner()),
            server: new ServerProbe($shell),
            craft: new CraftProbe(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(CheckContext $context): array
    {
        return [
            'php' => $context->php->summary(),
            'database' => $context->database->summary(),
            'server' => $context->server->summary(),
            'craft' => $context->craft->summary(),
            'scannedAt' => (new \DateTime())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Writes the result to the scan row and its findings.
     */
    private function store(ScanRecord $record, ScanResult $result): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record->status = ScanRecord::STATUS_COMPLETED;
            $record->score = $result->score();
            $record->categoryScores = Db::prepareValueForDb($result->categoryScores());
            $record->environment = Db::prepareValueForDb($result->environment);
            $record->skipped = Db::prepareValueForDb($result->skipped);
            $record->criticalCount = $result->countBySeverity(Finding::SEVERITY_CRITICAL);
            $record->warningCount = $result->countBySeverity(Finding::SEVERITY_WARNING);
            $record->noticeCount = $result->countBySeverity(Finding::SEVERITY_NOTICE);
            $record->passCount = $result->countBySeverity(Finding::SEVERITY_PASS);
            $record->runtime = $result->runtime;
            $record->dateFinished = Db::prepareDateForDb(new \DateTime());
            $record->save(false);

            foreach ($result->findings as $finding) {
                $row = new FindingRecord();
                $row->scanId = $record->id;
                $row->checkHandle = $finding->checkHandle;
                $row->category = $finding->category;
                $row->severity = $finding->severity;
                $row->title = $finding->title;
                $row->summary = $finding->summary;
                $row->observed = $finding->observed;
                $row->expected = $finding->expected;
                $row->impact = $finding->impact;
                $row->remediation = $finding->remediation;
                $row->docsUrl = $finding->docsUrl;
                $row->target = $finding->target;
                $row->occurrences = $finding->occurrences;
                $row->evidence = Db::prepareValueForDb($finding->evidence);
                $row->save(false);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------------

    public function getScanById(int $id): ?Scan
    {
        $record = ScanRecord::findOne($id);

        return $record === null ? null : Scan::fromRecord($record);
    }

    /**
     * A scan with its findings loaded.
     */
    public function getScanWithResult(int $id): ?Scan
    {
        $scan = $this->getScanById($id);

        if ($scan === null) {
            return null;
        }

        $scan->result = $this->getResultForScan($id);

        return $scan;
    }

    public function getResultForScan(int $scanId): ScanResult
    {
        $rows = (new Query())
            ->from(Install::TABLE_FINDINGS)
            ->where(['scanId' => $scanId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $result = new ScanResult();

        foreach ($rows as $row) {
            $finding = new Finding();
            $finding->checkHandle = (string)$row['checkHandle'];
            $finding->category = (string)$row['category'];
            $finding->severity = (string)$row['severity'];
            $finding->title = (string)$row['title'];
            $finding->summary = (string)($row['summary'] ?? '');
            $finding->observed = $row['observed'];
            $finding->expected = $row['expected'];
            $finding->impact = (string)($row['impact'] ?? '');
            $finding->remediation = (string)($row['remediation'] ?? '');
            $finding->docsUrl = $row['docsUrl'];
            $finding->target = $row['target'];
            $finding->occurrences = (int)$row['occurrences'];
            $finding->evidence = json_decode((string)($row['evidence'] ?? '[]'), true) ?: [];

            $result->addFinding($finding);
        }

        $scan = $this->getScanById($scanId);
        $result->environment = $scan->environment ?? [];
        $result->skipped = $scan->skipped ?? [];
        $result->runtime = $scan->runtime ?? 0.0;

        return $result;
    }

    /**
     * The most recent completed scan.
     */
    public function getLatestScan(): ?Scan
    {
        $record = ScanRecord::find()
            ->where(['status' => ScanRecord::STATUS_COMPLETED])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $record === null ? null : Scan::fromRecord($record);
    }

    /**
     * The completed scan before a given one — what "new since last time" is measured against.
     */
    public function getPreviousScan(int $scanId): ?Scan
    {
        $record = ScanRecord::find()
            ->where(['status' => ScanRecord::STATUS_COMPLETED])
            ->andWhere(['<', 'id', $scanId])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return $record === null ? null : Scan::fromRecord($record);
    }

    /**
     * @return Scan[]
     */
    public function getScans(int $limit = 50, int $offset = 0): array
    {
        $records = ScanRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(ScanRecord $record) => Scan::fromRecord($record), $records);
    }

    public function getTotalScans(): int
    {
        return (int)ScanRecord::find()->count();
    }

    /**
     * Score history, oldest first, for the dashboard chart.
     *
     * @return array<int, array{date: string, score: int, id: int}>
     */
    public function getScoreHistory(int $limit = 30): array
    {
        $rows = (new Query())
            ->select(['id', 'score', 'dateCreated'])
            ->from(Install::TABLE_SCANS)
            ->where(['status' => ScanRecord::STATUS_COMPLETED])
            ->andWhere(['not', ['score' => null]])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $history = array_map(fn(array $row) => [
            'id' => (int)$row['id'],
            'score' => (int)$row['score'],
            'date' => (string)$row['dateCreated'],
        ], $rows);

        return array_reverse($history);
    }

    /**
     * The last time a scan ran with the given trigger — how the scheduler knows whether it's due.
     */
    public function getLastRunAt(string $trigger): ?\DateTime
    {
        $date = (new Query())
            ->select(['dateCreated'])
            ->from(Install::TABLE_SCANS)
            ->where(['trigger' => $trigger])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->scalar();

        if ($date === false || $date === null) {
            return null;
        }

        return \craft\helpers\DateTimeHelper::toDateTime($date) ?: null;
    }

    public function deleteScan(int $id): bool
    {
        $record = ScanRecord::findOne($id);

        // Findings go with it via the foreign key's ON DELETE CASCADE.
        return $record !== null && (bool)$record->delete();
    }

    /**
     * Drops scans beyond the retention limit.
     *
     * Deleted by ID rather than by date so that two scans recorded in the same second can't
     * survive each other's pruning.
     */
    public function prune(?int $keep = null): int
    {
        $keep ??= Plugin::getInstance()->getSettings()->retainScans;

        $cutoff = (new Query())
            ->select(['id'])
            ->from(Install::TABLE_SCANS)
            ->orderBy(['id' => SORT_DESC])
            ->offset(max($keep - 1, 0))
            ->limit(1)
            ->scalar();

        if ($cutoff === false || $cutoff === null) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_SCANS, ['<', 'id', (int)$cutoff])
            ->execute();
    }
}
