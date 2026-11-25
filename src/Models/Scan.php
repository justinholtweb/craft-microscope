<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Data\ScanResult;
use JustinHoltWeb\Microscope\Database\Tables;
use JustinHoltWeb\Microscope\Services\Scans;
use Override;

use function CraftCms\Cms\cp_url;

/**
 * A stored scan: the header row, with its findings loaded on demand.
 *
 * The counts and scores are denormalised onto the row rather than recomputed from the findings, so
 * the scans index and the history chart are one query, not one per scan. Craft's `BaseModel` names
 * the timestamps `dateCreated`/`dateUpdated` and stores UTC, which is the Microscope 5 schema.
 *
 * @property int $id
 * @property string $status
 * @property string $trigger
 * @property ScanConfig $config
 * @property array<string, mixed>|null $environment
 * @property int|null $score
 * @property array<string, int>|null $categoryScores
 * @property int $criticalCount
 * @property int $warningCount
 * @property int $noticeCount
 * @property int $passCount
 * @property array<string, string>|null $skipped
 * @property float|null $runtime
 * @property string|null $error
 * @property int|null $creatorId
 * @property Carbon|null $dateStarted
 * @property Carbon|null $dateFinished
 * @property string $uid
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 */
class Scan extends BaseModel
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    /** The findings, once {@see Scans::withResult()} has loaded them. Not stored. */
    public ?ScanResult $result = null;

    #[Override]
    protected $table = Tables::SCANS;

    #[Override]
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'trigger' => ScanConfig::TRIGGER_MANUAL,
        'criticalCount' => 0,
        'warningCount' => 0,
        'noticeCount' => 0,
        'passCount' => 0,
    ];

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (Scan $scan): void {
            if (in_array($scan->getAttribute('uid'), [null, '', '0'], true)) {
                $scan->uid = Str::uuid()->toString();
            }
        });
    }

    /** @return HasMany<ScanFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(ScanFinding::class, 'scanId')->orderBy('id');
    }

    /**
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<Scan>  $query
     * @return Builder<Scan>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('dateCreated')->orderByDesc('id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function wasScheduled(): bool
    {
        return $this->trigger === ScanConfig::TRIGGER_SCHEDULED;
    }

    public function problemCount(): int
    {
        return $this->criticalCount + $this->warningCount + $this->noticeCount;
    }

    public function grade(): ?string
    {
        return $this->score === null ? null : ScanResult::gradeFor($this->score);
    }

    /** A CSS class suffix for the score, so the gauge and badges are coloured consistently. */
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

    public function cpUrl(): string
    {
        return cp_url("microscope/scans/{$this->id}");
    }

    /**
     * The scan's config, always as a {@see ScanConfig}. Stored as the same JSON Microscope 5 wrote.
     *
     * @return Attribute<ScanConfig, ScanConfig|array<string, mixed>>
     */
    protected function config(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => ScanConfig::fromArray(is_string($value) ? (array) json_decode($value, true) : []),
            set: fn (ScanConfig|array $value) => json_encode($value instanceof ScanConfig ? $value->toArray() : $value, JSON_THROW_ON_ERROR),
        );
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'score' => 'integer',
            'criticalCount' => 'integer',
            'warningCount' => 'integer',
            'noticeCount' => 'integer',
            'passCount' => 'integer',
            'runtime' => 'float',
            'creatorId' => 'integer',
            'environment' => 'array',
            'categoryScores' => 'array',
            'skipped' => 'array',
            'dateStarted' => 'datetime',
            'dateFinished' => 'datetime',
        ];
    }
}
