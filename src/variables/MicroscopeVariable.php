<?php

namespace justinholtweb\microscope\variables;

use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanResult;
use justinholtweb\microscope\Plugin;

/**
 * `craft.microscope` in templates.
 *
 * Read-only. Nothing here starts a scan — a template that could trigger a full site scan on a page
 * view is a denial-of-service waiting to happen.
 */
class MicroscopeVariable
{
    /**
     * The most recent completed scan.
     */
    public function latestScan(): ?Scan
    {
        return Plugin::getInstance()->scans->getLatestScan();
    }

    /**
     * The site's current score, or null if it has never been scanned.
     */
    public function score(): ?int
    {
        return $this->latestScan()?->score;
    }

    public function grade(): ?string
    {
        $score = $this->score();

        return $score === null ? null : ScanResult::gradeFor($score);
    }

    /**
     * @return Scan[]
     */
    public function scans(int $limit = 10): array
    {
        return Plugin::getInstance()->scans->getScans($limit);
    }

    public function scan(int $id): ?Scan
    {
        return Plugin::getInstance()->scans->getScanWithResult($id);
    }

    /**
     * Findings from the latest scan, optionally limited to one category.
     *
     * @return \justinholtweb\microscope\models\Finding[]
     */
    public function findings(?string $category = null): array
    {
        $scan = $this->latestScan();

        if ($scan === null) {
            return [];
        }

        return Plugin::getInstance()->scans
            ->getResultForScan((int)$scan->id)
            ->problems($category);
    }

    /**
     * @return array<int, array{date: string, score: int, id: int}>
     */
    public function history(int $limit = 30): array
    {
        return Plugin::getInstance()->scans->getScoreHistory($limit);
    }

    /**
     * @return string[]
     */
    public function categories(): array
    {
        return Category::all();
    }

    public function categoryLabel(string $category): string
    {
        return Category::label($category);
    }

    public function nextScheduledScan(): ?\DateTime
    {
        return Plugin::getInstance()->schedules->nextRunAt();
    }
}
