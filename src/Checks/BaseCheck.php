<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks;

use JustinHoltWeb\Microscope\Data\Finding;

use function CraftCms\Cms\t;

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
        return t('Not available in this environment.', category: 'microscope');
    }

    /**
     * @param  array<string, mixed>  $attributes  Any of {@see Finding}'s public properties.
     */
    protected function finding(string $severity, array $attributes): Finding
    {
        return Finding::make($this->handle(), $this->category(), $severity, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function critical(array $attributes): Finding
    {
        return $this->finding(Finding::SEVERITY_CRITICAL, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function warning(array $attributes): Finding
    {
        return $this->finding(Finding::SEVERITY_WARNING, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    protected function notice(array $attributes): Finding
    {
        return $this->finding(Finding::SEVERITY_NOTICE, $attributes);
    }

    /**
     * A check that found nothing wrong. Passing findings carry what was observed, so a report
     * shows the actual OPcache size next to the tick rather than an unqualified "fine".
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
     * Picks a severity by how far short of a threshold something is, so a value at half the
     * recommendation is a warning everywhere.
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
