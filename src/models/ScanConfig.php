<?php

namespace justinholtweb\microscope\models;

use craft\base\Model;

/**
 * What one particular scan was asked to do.
 *
 * Separate from {@see Settings} because a scan is a historical record: the config is stored on the
 * scan row, so a report from three months ago can say which categories it covered even after the
 * site's settings have changed. Comparing a full scan against a PHP-only one would otherwise look
 * like a dramatic improvement.
 */
class ScanConfig extends Model
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_CONSOLE = 'console';

    /**
     * @var string[] Categories to run. Empty means all of them.
     */
    public array $categories = [];

    /**
     * @var string[] Check handles to skip, on top of anything disabled in settings.
     */
    public array $skipChecks = [];

    /**
     * @var string What started this scan.
     */
    public string $trigger = self::TRIGGER_MANUAL;

    /**
     * @var bool Whether to record a passing finding for every check that found nothing.
     *
     * On for reports, because "we checked 29 things and 24 were fine" is most of what a client
     * wants from one. Off for the scheduler's own comparisons, where only problems matter.
     */
    public bool $includePasses = true;

    public function rules(): array
    {
        return [
            [['trigger'], 'in', 'range' => [self::TRIGGER_MANUAL, self::TRIGGER_SCHEDULED, self::TRIGGER_CONSOLE]],
            [['categories'], 'validateCategories'],
        ];
    }

    public function validateCategories(string $attribute): void
    {
        foreach ($this->categories as $category) {
            if (!Category::exists($category)) {
                $this->addError($attribute, \Craft::t('microscope', 'Unknown category: {category}', [
                    'category' => $category,
                ]));
            }
        }
    }

    /**
     * The categories this scan actually covers.
     *
     * @return string[]
     */
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
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'categories' => $this->categories,
            'skipChecks' => $this->skipChecks,
            'trigger' => $this->trigger,
            'includePasses' => $this->includePasses,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $config = new self();
        $config->setAttributes($data, false);

        return $config;
    }
}
