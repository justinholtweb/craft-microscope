<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Database\Tables;
use Override;

/**
 * One stored finding: a {@see Finding} as a database row.
 *
 * @property int $id
 * @property int $scanId
 * @property string $checkHandle
 * @property string $category
 * @property string $severity
 * @property string $title
 * @property string|null $summary
 * @property string|null $observed
 * @property string|null $expected
 * @property string|null $impact
 * @property string|null $remediation
 * @property string|null $docsUrl
 * @property string|null $target
 * @property int $occurrences
 * @property array<int, array<string, mixed>>|null $evidence
 * @property string $uid
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 */
class ScanFinding extends BaseModel
{
    #[Override]
    protected $table = Tables::FINDINGS;

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (ScanFinding $finding): void {
            if (in_array($finding->getAttribute('uid'), [null, '', '0'], true)) {
                $finding->uid = Str::uuid()->toString();
            }
        });
    }

    /**
     * @return array<string, mixed> Row attributes for a finding, ready for `create()` / `insert()`.
     */
    public static function attributesFor(Finding $finding): array
    {
        return [
            'checkHandle' => mb_substr($finding->checkHandle, 0, 96),
            'category' => $finding->category,
            'severity' => $finding->severity,
            'title' => mb_substr($finding->title, 0, 255),
            'summary' => $finding->summary,
            'observed' => $finding->observed !== null ? mb_substr($finding->observed, 0, 255) : null,
            'expected' => $finding->expected !== null ? mb_substr($finding->expected, 0, 255) : null,
            'impact' => $finding->impact,
            'remediation' => $finding->remediation,
            'docsUrl' => $finding->docsUrl !== null ? mb_substr($finding->docsUrl, 0, 500) : null,
            'target' => $finding->target !== null ? mb_substr($finding->target, 0, 500) : null,
            'occurrences' => $finding->occurrences,
            'evidence' => $finding->evidence,
        ];
    }

    public function toFinding(): Finding
    {
        return Finding::fromArray([
            'checkHandle' => $this->checkHandle,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'summary' => $this->summary ?? '',
            'observed' => $this->observed,
            'expected' => $this->expected,
            'impact' => $this->impact ?? '',
            'remediation' => $this->remediation ?? '',
            'docsUrl' => $this->docsUrl,
            'target' => $this->target,
            'occurrences' => $this->occurrences,
            'evidence' => $this->evidence ?? [],
        ]);
    }

    /** @return BelongsTo<Scan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scanId');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'scanId' => 'integer',
            'occurrences' => 'integer',
            'evidence' => 'array',
        ];
    }
}
