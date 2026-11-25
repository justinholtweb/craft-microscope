<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Support;

use Carbon\CarbonInterface;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanResult;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;

/**
 * Microscope in templates: `microscope.score()` in Twig, `$microscope->score()` in Blade, and
 * `craft.microscope.score()` as in Microscope 5.
 *
 * Read-only. Nothing here starts a scan: a template that could trigger a full site scan on a page
 * view would be a denial of service waiting to happen.
 */
class MicroscopeVariable
{
    public function __construct(
        private readonly Scans $scans,
        private readonly Schedules $schedules,
    ) {}

    /** The most recent completed scan. */
    public function latestScan(): ?Scan
    {
        return $this->scans->getLatestScan();
    }

    /** The site's current score, or null if it has never been scanned. */
    public function score(): ?int
    {
        return $this->latestScan()?->score;
    }

    public function grade(): ?string
    {
        $score = $this->score();

        return $score === null ? null : ScanResult::gradeFor($score);
    }

    /** @return list<Scan> */
    public function scans(int $limit = 10): array
    {
        return $this->scans->getScans($limit);
    }

    public function scan(int $id): ?Scan
    {
        return $this->scans->getScanWithResult($id);
    }

    /**
     * Problems from the latest scan, optionally in one category.
     *
     * @return list<Finding>
     */
    public function findings(?string $category = null): array
    {
        $scan = $this->latestScan();

        return $scan === null ? [] : $this->scans->getResultForScan($scan)->problems($category);
    }

    /** @return list<array{id: int, score: int, date: string}> */
    public function history(int $limit = 30): array
    {
        return $this->scans->getScoreHistory($limit);
    }

    /** @return list<string> */
    public function categories(): array
    {
        return Category::all();
    }

    public function categoryLabel(string $category): string
    {
        return Category::label($category);
    }

    public function nextScheduledScan(): ?CarbonInterface
    {
        return $this->schedules->nextRunAt();
    }
}
