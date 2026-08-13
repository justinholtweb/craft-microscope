<?php

namespace justinholtweb\microscope\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\microscope\records\ScanRecord;

/**
 * A stored scan: the header row, with its findings loaded on demand.
 *
 * The counts and scores are denormalised onto this rather than recomputed from the findings, so
 * the scans index and the history chart can be drawn from one query instead of one per scan.
 */
class Scan extends Model
{
    public ?int $id = null;
    public string $status = ScanRecord::STATUS_PENDING;
    public string $trigger = ScanConfig::TRIGGER_MANUAL;
    public ?int $score = null;
    public int $criticalCount = 0;
    public int $warningCount = 0;
    public int $noticeCount = 0;
    public int $passCount = 0;
    public ?float $runtime = null;
    public ?string $error = null;
    public ?int $creatorId = null;
    public ?DateTime $dateStarted = null;
    public ?DateTime $dateFinished = null;
    public ?DateTime $dateCreated = null;

    /**
     * @var array<string, int>
     */
    public array $categoryScores = [];

    /**
     * @var array<string, mixed>
     */
    public array $environment = [];

    /**
     * @var array<string, string> Checks that didn't run, keyed by handle, with the reason.
     */
    public array $skipped = [];

    /**
     * @var ScanConfig|null
     */
    public ?ScanConfig $config = null;

    /**
     * @var ScanResult|null Loaded lazily by the Scans service.
     */
    public ?ScanResult $result = null;

    public function isCompleted(): bool
    {
        return $this->status === ScanRecord::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === ScanRecord::STATUS_FAILED;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [ScanRecord::STATUS_PENDING, ScanRecord::STATUS_RUNNING], true);
    }

    public function problemCount(): int
    {
        return $this->criticalCount + $this->warningCount + $this->noticeCount;
    }

    public function grade(): string
    {
        return ScanResult::gradeFor($this->score ?? 0);
    }

    /**
     * A CSS class suffix for the score, used to colour the gauge and badges consistently.
     */
    public function tone(): string
    {
        return match (true) {
            $this->score === null => 'unknown',
            $this->score >= 90 => 'good',
            $this->score >= 70 => 'fair',
            $this->score >= 50 => 'poor',
            default => 'bad',
        };
    }

    public function wasScheduled(): bool
    {
        return $this->trigger === ScanConfig::TRIGGER_SCHEDULED;
    }

    /**
     * Builds a model from its database row.
     */
    public static function fromRecord(ScanRecord $record): self
    {
        $scan = new self();
        $scan->id = (int)$record->id;
        $scan->status = $record->status;
        $scan->trigger = $record->trigger;
        $scan->score = $record->score !== null ? (int)$record->score : null;
        $scan->criticalCount = (int)$record->criticalCount;
        $scan->warningCount = (int)$record->warningCount;
        $scan->noticeCount = (int)$record->noticeCount;
        $scan->passCount = (int)$record->passCount;
        $scan->runtime = $record->runtime !== null ? (float)$record->runtime : null;
        $scan->error = $record->error;
        $scan->creatorId = $record->creatorId !== null ? (int)$record->creatorId : null;
        $scan->categoryScores = self::decode($record->categoryScores);
        $scan->environment = self::decode($record->environment);
        $scan->skipped = self::decode($record->skipped);
        $scan->config = ScanConfig::fromArray(self::decode($record->config));

        foreach (['dateStarted', 'dateFinished', 'dateCreated'] as $attribute) {
            $value = $record->$attribute ?? null;
            $scan->$attribute = $value ? DateTimeHelper::toDateTime($value) ?: null : null;
        }

        return $scan;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
