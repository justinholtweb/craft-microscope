<?php

namespace justinholtweb\microscope\models;

use craft\base\Model;

/**
 * Everything one scan found, plus the arithmetic the reports and dashboard do on it.
 *
 * Kept free of Craft services on purpose: a result can be rebuilt from a stored scan row and
 * behave identically, which is what lets the report route render a scan from last month.
 */
class ScanResult extends Model
{
    /**
     * @var Finding[]
     */
    public array $findings = [];

    /**
     * @var array<string, mixed> What the probes measured, kept alongside the findings so a report
     *                           can show the environment a scan ran in — PHP version, database
     *                           server, memory, and so on.
     */
    public array $environment = [];

    /**
     * @var string[] Handles of checks that could not run, and why, keyed by handle.
     */
    public array $skipped = [];

    /**
     * @var float Seconds the scan took.
     */
    public float $runtime = 0.0;

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /**
     * @param Finding[] $findings
     */
    public function addFindings(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->addFinding($finding);
        }
    }

    /**
     * Findings worth acting on, hardest first, then alphabetically so the order is stable between
     * two scans that found the same things.
     *
     * @return Finding[]
     */
    public function problems(?string $category = null): array
    {
        $problems = array_filter(
            $this->findings,
            fn(Finding $f) => $f->isProblem() && ($category === null || $f->category === $category),
        );

        usort($problems, function(Finding $a, Finding $b) {
            return [$a->rank(), $a->category, $a->title] <=> [$b->rank(), $b->category, $b->title];
        });

        return array_values($problems);
    }

    /**
     * @return Finding[]
     */
    public function passes(?string $category = null): array
    {
        return array_values(array_filter(
            $this->findings,
            fn(Finding $f) => !$f->isProblem() && ($category === null || $f->category === $category),
        ));
    }

    /**
     * @return Finding[]
     */
    public function bySeverity(string $severity): array
    {
        return array_values(array_filter($this->findings, fn(Finding $f) => $f->severity === $severity));
    }

    public function countBySeverity(string $severity): int
    {
        return count($this->bySeverity($severity));
    }

    /**
     * Findings grouped by category, in {@see Category::all()} order, with empty categories
     * dropped.
     *
     * @return array<string, Finding[]>
     */
    public function groupedByCategory(bool $problemsOnly = true): array
    {
        $grouped = [];

        foreach (Category::all() as $category) {
            $findings = $problemsOnly ? $this->problems($category) : array_values(array_filter(
                $this->findings,
                fn(Finding $f) => $f->category === $category,
            ));

            if ($findings !== []) {
                $grouped[$category] = $findings;
            }
        }

        return $grouped;
    }

    /**
     * The score for one category, 0–100.
     *
     * Costs are subtracted rather than averaged. Averaging rewards padding a category with easy
     * passing checks, which would make the score go *up* every time a check is added — the number
     * has to mean "how much is wrong here", not "what fraction of my checks passed".
     */
    public function categoryScore(string $category): int
    {
        $findings = array_filter($this->findings, fn(Finding $f) => $f->category === $category);

        if ($findings === []) {
            return 100;
        }

        $cost = array_sum(array_map(fn(Finding $f) => $f->cost(), $findings));

        return (int)max(0, 100 - $cost);
    }

    /**
     * @return array<string, int>
     */
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
     * The overall score: the mean of the categories that actually ran.
     *
     * Categories are weighted equally and skipped categories are left out entirely, so a scan that
     * couldn't reach the shell doesn't quietly score the site down for it.
     */
    public function score(): int
    {
        $scores = $this->categoryScores();

        if ($scores === []) {
            return 100;
        }

        return (int)round(array_sum($scores) / count($scores));
    }

    /**
     * A letter for the score, for the dashboard gauge and the report cover.
     */
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

    /**
     * One line summarising the scan, used in emails, the widget and the scans index.
     */
    public function headline(): string
    {
        $critical = $this->countBySeverity(Finding::SEVERITY_CRITICAL);
        $warning = $this->countBySeverity(Finding::SEVERITY_WARNING);
        $notice = $this->countBySeverity(Finding::SEVERITY_NOTICE);

        if ($critical + $warning + $notice === 0) {
            return \Craft::t('microscope', 'No performance issues found.');
        }

        return \Craft::t('microscope', '{critical} critical, {warning} warnings, {notice} notices', [
            'critical' => $critical,
            'warning' => $warning,
            'notice' => $notice,
        ]);
    }

    /**
     * Fingerprints of every outstanding problem, for comparing one scan against another.
     *
     * @return string[]
     */
    public function fingerprints(): array
    {
        return array_map(fn(Finding $f) => $f->fingerprint(), $this->problems());
    }

    /**
     * Problems present here that weren't in the given (earlier) scan.
     *
     * @return Finding[]
     */
    public function newSince(?ScanResult $previous): array
    {
        if ($previous === null) {
            return $this->problems();
        }

        $before = $previous->fingerprints();

        return array_values(array_filter(
            $this->problems(),
            fn(Finding $f) => !in_array($f->fingerprint(), $before, true),
        ));
    }

    /**
     * Problems in the earlier scan that are gone from this one.
     *
     * @return Finding[]
     */
    public function resolvedSince(?ScanResult $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $now = $this->fingerprints();

        return array_values(array_filter(
            $previous->problems(),
            fn(Finding $f) => !in_array($f->fingerprint(), $now, true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'findings' => array_map(fn(Finding $f) => $f->toArray(), $this->findings),
            'environment' => $this->environment,
            'skipped' => $this->skipped,
            'runtime' => $this->runtime,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self();
        $result->environment = $data['environment'] ?? [];
        $result->skipped = $data['skipped'] ?? [];
        $result->runtime = (float)($data['runtime'] ?? 0);

        foreach ($data['findings'] ?? [] as $finding) {
            $result->addFinding(Finding::fromArray($finding));
        }

        return $result;
    }
}
