<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Data;

/**
 * What one particular scan was asked to do.
 *
 * Stored on the scan row, so a report from three months ago can say which categories it covered
 * even after the site's settings have changed. Same array shape as Microscope 5.
 */
final class ScanConfig
{
    public const string TRIGGER_MANUAL = 'manual';

    public const string TRIGGER_SCHEDULED = 'scheduled';

    public const string TRIGGER_CONSOLE = 'console';

    public const array TRIGGERS = [self::TRIGGER_MANUAL, self::TRIGGER_SCHEDULED, self::TRIGGER_CONSOLE];

    /**
     * @param  list<string>  $categories  Categories to run. Empty means all of them.
     * @param  list<string>  $skipChecks  Check handles to skip, on top of anything disabled in settings.
     * @param  bool  $includePasses  Record a passing finding for every check that found nothing.
     */
    public function __construct(
        public array $categories = [],
        public array $skipChecks = [],
        public string $trigger = self::TRIGGER_MANUAL,
        public bool $includePasses = true,
    ) {
        $this->categories = array_values(array_unique(array_filter($categories, Category::exists(...))));
        $this->skipChecks = array_values(array_map('strval', $skipChecks));

        if (! in_array($this->trigger, self::TRIGGERS, true)) {
            $this->trigger = self::TRIGGER_MANUAL;
        }
    }

    /** @return list<string> */
    public function resolvedCategories(): array
    {
        return $this->categories === [] ? Category::all() : $this->categories;
    }

    public function coversCategory(string $category): bool
    {
        return in_array($category, $this->resolvedCategories(), true);
    }

    public function isFullScan(): bool
    {
        return $this->categories === [] || count($this->categories) === count(Category::all());
    }

    /**
     * @return array{categories: list<string>, skipChecks: list<string>, trigger: string, includePasses: bool}
     */
    public function toArray(): array
    {
        return [
            'categories' => $this->categories,
            'skipChecks' => $this->skipChecks,
            'trigger' => $this->trigger,
            'includePasses' => $this->includePasses,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            categories: array_values(array_map('strval', (array) ($data['categories'] ?? []))),
            skipChecks: array_values(array_map('strval', (array) ($data['skipChecks'] ?? []))),
            trigger: (string) ($data['trigger'] ?? self::TRIGGER_MANUAL),
            includePasses: (bool) ($data['includePasses'] ?? true),
        );
    }
}
