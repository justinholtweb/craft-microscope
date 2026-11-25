<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Data;

use function CraftCms\Cms\t;

/**
 * Everything one scan found, plus the arithmetic the reports and dashboard do on it.
 *
 * Kept free of Craft services on purpose: a result can be rebuilt from a stored scan and behave
 * identically, which is what lets a report render a scan from last month.
 */
final class ScanResult
{
    /** @var list<Finding> */
    public array $findings = [];

    /** @var array<string, mixed> What the probes measured: PHP version, database server, memory… */
    public array $environment = [];

    /** @var array<string, string> Checks that couldn't run, keyed by handle, with the reason. */
    public array $skipped = [];

    /** Seconds the scan took. */
    public float $runtime = 0.0;

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /**
     * @param  iterable<Finding>  $findings
     */
    public function addFindings(iterable $findings): void
    {
        foreach ($findings as $finding) {
            $this->addFinding($finding);
        }
    }

    /**
     * Findings worth acting on, hardest first, then alphabetically so the order is stable between
     * two scans that found the same things.
     *
     * @return list<Finding>
     */
    public function problems(?string $category = null): array
    {
        $problems = array_filter(
            $this->findings,
            fn (Finding $f) => $f->isProblem() && ($category === null || $f->category === $category),
        );

        usort($problems, fn (Finding $a, Finding $b) => [$a->rank(), $a->category, $a->title] <=> [$b->rank(), $b->category, $b->title]);

        return array_values($problems);
    }

    /** @return list<Finding> */
    public function passes(?string $category = null): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (Finding $f) => ! $f->isProblem() && ($category === null || $f->category === $category),
        ));
    }

    /** @return list<Finding> */
    public function bySeverity(string $severity): array
    {
        return array_values(array_filter($this->findings, fn (Finding $f) => $f->severity === $severity));
    }

    public function countBySeverity(string $severity): int
    {
        return count($this->bySeverity($severity));
    }

    /**
     * Findings grouped by category, in {@see Category::all()} order, with empty categories dropped.
     *
     * @return array<string, list<Finding>>
     */
    public function groupedByCategory(bool $problemsOnly = true): array
    {
        $grouped = [];

        foreach (Category::all() as $category) {
            $findings = $problemsOnly ? $this->problems($category) : array_values(array_filter(
                $this->findings,
                fn (Finding $f) => $f->category === $category,
            ));

            if ($findings !== []) {
                $grouped[$category] = $findings;
            }
        }

        return $grouped;
    }

    /**
     * The score for one category, 0–100. Costs are subtracted rather than averaged, so adding an
     * easy passing check never makes the score go up.
     */
    public function categoryScore(string $category): int
    {
        $findings = array_filter($this->findings, fn (Finding $f) => $f->category === $category);

        if ($findings === []) {
            return 100;
        }

        return max(0, 100 - array_sum(array_map(fn (Finding $f) => $f->cost(), $findings)));
    }

    /** @return array<string, int> */
    public function categoryScores(): array
    {
        $scores = [];

        foreach (Category::all() as $category) {
            if ($this->hasCategory($category)) {
                $scores[$category] = $this->categoryScore($category);
            }
        }

        return $scores;
    }

    public function hasCategory(string $category): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->category === $category) {
                return true;
            }
        }

        return false;
    }

    /**
     * The overall score: the mean of the categories that actually ran. Skipped categories are left
     * out, so a scan that couldn't reach the shell doesn't score the site down for it.
     */
    public function score(): int
    {
        $scores = $this->categoryScores();

        return $scores === [] ? 100 : (int) round(array_sum($scores) / count($scores));
    }

    public function grade(): string
    {
        return self::gradeFor($this->score());
    }

    public static function gradeFor(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /** One line summarising the scan, used in emails, the widget and the scans index. */
    public function headline(): string
    {
        $critical = $this->countBySeverity(Finding::SEVERITY_CRITICAL);
        $warning = $this->countBySeverity(Finding::SEVERITY_WARNING);
        $notice = $this->countBySeverity(Finding::SEVERITY_NOTICE);

        if ($critical + $warning + $notice === 0) {
            return t('No performance issues found.', category: 'microscope');
        }

        return t('{critical} critical, {warning} warnings, {notice} notices', [
            'critical' => $critical,
            'warning' => $warning,
            'notice' => $notice,
        ], category: 'microscope');
    }

    /** @return list<string> Fingerprints of every outstanding problem. */
    public function fingerprints(): array
    {
        return array_map(fn (Finding $f) => $f->fingerprint(), $this->problems());
    }

    /**
     * Problems present here that weren't in the given (earlier) scan.
     *
     * @return list<Finding>
     */
    public function newSince(?self $previous): array
    {
        if ($previous === null) {
            return $this->problems();
        }

        $before = $previous->fingerprints();

        return array_values(array_filter($this->problems(), fn (Finding $f) => ! in_array($f->fingerprint(), $before, true)));
    }

    /**
     * Problems in the earlier scan that are gone from this one.
     *
     * @return list<Finding>
     */
    public function resolvedSince(?self $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $now = $this->fingerprints();

        return array_values(array_filter($previous->problems(), fn (Finding $f) => ! in_array($f->fingerprint(), $now, true)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->findings),
            'environment' => $this->environment,
            'skipped' => $this->skipped,
            'runtime' => $this->runtime,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self;
        $result->environment = (array) ($data['environment'] ?? []);
        $result->skipped = array_map('strval', (array) ($data['skipped'] ?? []));
        $result->runtime = (float) ($data['runtime'] ?? 0);

        foreach ((array) ($data['findings'] ?? []) as $finding) {
            $result->addFinding(Finding::fromArray((array) $finding));
        }

        return $result;
    }
}
