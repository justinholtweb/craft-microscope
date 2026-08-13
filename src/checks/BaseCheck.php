<?php

namespace justinholtweb\microscope\checks;

use Craft;
use justinholtweb\microscope\models\Finding;

/**
 * Shared behaviour for checks: building findings without repeating the boilerplate.
 */
abstract class BaseCheck implements CheckInterface
{
    public function isAvailable(CheckContext $context): bool
    {
        return true;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return Craft::t('microscope', 'Not available in this environment.');
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function finding(string $severity, array $config): Finding
    {
        $finding = new Finding();
        $finding->checkHandle = $this->handle();
        $finding->category = $this->category();
        $finding->severity = $severity;
        $finding->setAttributes($config, false);

        return $finding;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function critical(array $config): Finding
    {
        return $this->finding(Finding::SEVERITY_CRITICAL, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function warning(array $config): Finding
    {
        return $this->finding(Finding::SEVERITY_WARNING, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function notice(array $config): Finding
    {
        return $this->finding(Finding::SEVERITY_NOTICE, $config);
    }

    /**
     * A check that found nothing wrong.
     *
     * Passing findings carry no remediation — there is nothing to remediate — but they do carry
     * what was observed, so a report shows the actual OPcache size next to the tick rather than
     * an unqualified "fine".
     */
    protected function pass(string $title, ?string $observed = null, string $summary = ''): Finding
    {
        return $this->finding(Finding::SEVERITY_PASS, [
            'title' => $title,
            'observed' => $observed,
            'summary' => $summary,
        ]);
    }

    /**
     * Picks a severity by how far past a threshold something is.
     *
     * Saves every numeric check from re-implementing the same ladder, and keeps the boundaries
     * consistent between them — a value at half the recommendation is a warning everywhere, not a
     * warning in one check and critical in another.
     */
    protected function severityForRatio(float $ratio): string
    {
        return match (true) {
            $ratio < 0.25 => Finding::SEVERITY_CRITICAL,
            $ratio < 0.75 => Finding::SEVERITY_WARNING,
            default => Finding::SEVERITY_NOTICE,
        };
    }
}
