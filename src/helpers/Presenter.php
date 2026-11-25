<?php

namespace justinholtweb\microscope\helpers;

use DateTimeInterface;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanResult;

/**
 * The one output shape for scans and findings, shared by the MCP tools and GraphQL.
 *
 * Everything here is plain arrays built from Microscope's models, with no Craft application
 * needed, so the shapes can be pinned down by unit tests. The names are part of a public contract
 * (MCP tool output and GraphQL fields, identical in Microscope 5 and 6): add keys, never rename.
 */
final class Presenter
{
    /** How many evidence rows a finding carries before the rest are only counted. */
    public const EVIDENCE_LIMIT = 25;

    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }

    /**
     * A scan's header: the `ScanSummary` shape.
     *
     * @return array<string, mixed>
     */
    public static function scan(Scan $scan, ?string $url = null): array
    {
        return [
            'id' => (int)$scan->id,
            'status' => $scan->status,
            'trigger' => $scan->trigger,
            'score' => $scan->score,
            'grade' => $scan->score !== null ? ScanResult::gradeFor($scan->score) : null,
            'criticalCount' => $scan->criticalCount,
            'warningCount' => $scan->warningCount,
            'noticeCount' => $scan->noticeCount,
            'passCount' => $scan->passCount,
            'runtime' => $scan->runtime,
            'error' => $scan->error,
            'dateStarted' => self::date($scan->dateStarted),
            'dateFinished' => self::date($scan->dateFinished),
            'dateCreated' => self::date($scan->dateCreated),
            'categoryScores' => array_map('intval', $scan->categoryScores),
            'url' => $url,
        ];
    }

    /**
     * One finding: the `Finding` shape.
     *
     * @return array<string, mixed>
     */
    public static function finding(Finding $finding, bool $isNew = false, bool $includeEvidence = false): array
    {
        $data = [
            'checkHandle' => $finding->checkHandle,
            'category' => $finding->category,
            'severity' => $finding->severity,
            'title' => $finding->title,
            'summary' => $finding->summary,
            'observed' => $finding->observed,
            'expected' => $finding->expected,
            'impact' => $finding->impact,
            'remediation' => $finding->remediation,
            'docsUrl' => $finding->docsUrl,
            'target' => $finding->target,
            'occurrences' => $finding->occurrences,
            'fingerprint' => $finding->fingerprint(),
            'isNew' => $isNew,
        ];

        if ($includeEvidence) {
            $evidence = array_values($finding->evidence);
            $data['evidence'] = array_slice($evidence, 0, self::EVIDENCE_LIMIT);
            $data['evidenceTotal'] = count($evidence);
        }

        return $data;
    }

    /**
     * A finding that has gone since the previous scan.
     *
     * @return array{checkHandle: string, title: string, severity: string, target: string|null}
     */
    public static function resolved(Finding $finding): array
    {
        return [
            'checkHandle' => $finding->checkHandle,
            'title' => $finding->title,
            'severity' => $finding->severity,
            'target' => $finding->target,
        ];
    }

    /**
     * Checks that didn't run, as a list.
     *
     * @param array<string, string> $skipped
     * @return list<array{checkHandle: string, reason: string}>
     */
    public static function skipped(array $skipped): array
    {
        $list = [];

        foreach ($skipped as $handle => $reason) {
            $list[] = ['checkHandle' => (string)$handle, 'reason' => (string)$reason];
        }

        return $list;
    }

    /**
     * A result's findings, filtered and in severity order (critical first), the way the control
     * panel lists them.
     *
     * A `pass` severity filter implies passes are wanted, whatever `includePasses` says.
     *
     * @param string[] $newFingerprints Fingerprints of findings that are new since the last scan.
     * @return list<array<string, mixed>>
     */
    public static function findings(
        ScanResult $result,
        array $newFingerprints = [],
        ?string $category = null,
        ?string $severity = null,
        bool $includePasses = false,
        bool $onlyNew = false,
        bool $includeEvidence = false,
    ): array {
        $includePasses = $includePasses || $severity === Finding::SEVERITY_PASS;

        $findings = array_values(array_filter(
            $result->findings,
            fn(Finding $f) => ($includePasses || $f->isProblem())
                && ($category === null || $f->category === $category)
                && ($severity === null || $f->severity === $severity)
                && (!$onlyNew || in_array($f->fingerprint(), $newFingerprints, true)),
        ));

        // usort is stable (PHP 8), so equal ranks keep the order the checks reported them in.
        usort($findings, fn(Finding $a, Finding $b) => $a->rank() <=> $b->rank());

        return array_map(
            fn(Finding $f) => self::finding($f, in_array($f->fingerprint(), $newFingerprints, true), $includeEvidence),
            $findings,
        );
    }

    /**
     * @param Finding[] $findings
     * @return string[]
     */
    public static function fingerprints(array $findings): array
    {
        return array_values(array_map(fn(Finding $f) => $f->fingerprint(), $findings));
    }
}
