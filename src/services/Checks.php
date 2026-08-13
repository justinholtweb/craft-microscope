<?php

namespace justinholtweb\microscope\services;

use Craft;
use justinholtweb\microscope\checks\CheckInterface;
use justinholtweb\microscope\checks\craft\CacheConfigCheck;
use justinholtweb\microscope\checks\craft\DevModeCheck;
use justinholtweb\microscope\checks\craft\GarbageCollectionCheck;
use justinholtweb\microscope\checks\craft\ImageHandlingCheck;
use justinholtweb\microscope\checks\craft\ProjectConfigCheck;
use justinholtweb\microscope\checks\craft\QueueRunnerCheck;
use justinholtweb\microscope\checks\database\ConnectionLimitsCheck;
use justinholtweb\microscope\checks\database\DatabaseVersionCheck;
use justinholtweb\microscope\checks\database\InnodbBufferPoolCheck;
use justinholtweb\microscope\checks\database\QueryCacheCheck;
use justinholtweb\microscope\checks\database\SlowQueryLogCheck;
use justinholtweb\microscope\checks\database\TableBloatCheck;
use justinholtweb\microscope\checks\database\TempTableCheck;
use justinholtweb\microscope\checks\php\ExtensionsCheck;
use justinholtweb\microscope\checks\php\MemoryLimitCheck;
use justinholtweb\microscope\checks\php\OpcacheCheck;
use justinholtweb\microscope\checks\php\PhpVersionCheck;
use justinholtweb\microscope\checks\php\RealpathCacheCheck;
use justinholtweb\microscope\checks\php\RequestLimitsCheck;
use justinholtweb\microscope\checks\schema\ContentVolumeCheck;
use justinholtweb\microscope\checks\schema\FieldLayoutCheck;
use justinholtweb\microscope\checks\schema\FieldUsageCheck;
use justinholtweb\microscope\checks\server\CompressionCheck;
use justinholtweb\microscope\checks\server\DiskSpaceCheck;
use justinholtweb\microscope\checks\server\HttpProtocolCheck;
use justinholtweb\microscope\checks\server\SystemResourcesCheck;
use justinholtweb\microscope\checks\templates\TemplateCheck;
use justinholtweb\microscope\events\RegisterChecksEvent;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\Plugin;
use yii\base\Component;

/**
 * The catalogue of checks.
 *
 * @property-read CheckInterface[] $allChecks
 */
class Checks extends Component
{
    /**
     * @event RegisterChecksEvent Fired when the catalogue is assembled.
     */
    public const EVENT_REGISTER_CHECKS = 'registerChecks';

    /**
     * @var CheckInterface[]|null
     */
    private ?array $checks = null;

    /**
     * Every registered check, in the order they run and are reported.
     *
     * The order within a category is roughly "most consequential first", so a report reads
     * top-down as a priority list even before severity sorting is applied.
     *
     * @return CheckInterface[]
     */
    public function getAllChecks(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        $event = new RegisterChecksEvent([
            'checks' => [
                // PHP
                new OpcacheCheck(),
                new PhpVersionCheck(),
                new MemoryLimitCheck(),
                new RequestLimitsCheck(),
                new ExtensionsCheck(),
                new RealpathCacheCheck(),

                // Database
                new InnodbBufferPoolCheck(),
                new DatabaseVersionCheck(),
                new TableBloatCheck(),
                new TempTableCheck(),
                new SlowQueryLogCheck(),
                new ConnectionLimitsCheck(),
                new QueryCacheCheck(),

                // Server
                new SystemResourcesCheck(),
                new CompressionCheck(),
                new HttpProtocolCheck(),
                new DiskSpaceCheck(),

                // Craft
                new DevModeCheck(),
                new CacheConfigCheck(),
                new QueueRunnerCheck(),
                new ImageHandlingCheck(),
                new GarbageCollectionCheck(),
                new ProjectConfigCheck(),

                // Schema
                new FieldLayoutCheck(),
                new FieldUsageCheck(),
                new ContentVolumeCheck(),

                // Templates
                new TemplateCheck(),
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_CHECKS, $event);

        return $this->checks = $event->checks;
    }

    public function getCheck(string $handle): ?CheckInterface
    {
        foreach ($this->getAllChecks() as $check) {
            if ($check->handle() === $handle) {
                return $check;
            }
        }

        return null;
    }

    /**
     * Checks in one category.
     *
     * @return CheckInterface[]
     */
    public function getChecksByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->getAllChecks(),
            fn(CheckInterface $check) => $check->category() === $category,
        ));
    }

    /**
     * The catalogue grouped for the control panel, in {@see Category::all()} order.
     *
     * @return array<string, CheckInterface[]>
     */
    public function getGroupedChecks(): array
    {
        $grouped = [];

        foreach (Category::all() as $category) {
            $checks = $this->getChecksByCategory($category);

            if ($checks !== []) {
                $grouped[$category] = $checks;
            }
        }

        return $grouped;
    }

    /**
     * Whether a check is switched on for this site.
     */
    public function isEnabled(string $handle): bool
    {
        return Plugin::getInstance()->getSettings()->checkIsEnabled($handle);
    }

    /**
     * Turns a check on or off and saves the settings.
     */
    public function setEnabled(string $handle, bool $enabled): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $disabled = $settings->disabledChecks;

        if ($enabled) {
            $disabled = array_values(array_diff($disabled, [$handle]));
        } elseif (!in_array($handle, $disabled, true)) {
            $disabled[] = $handle;
        }

        $settings->disabledChecks = $disabled;

        return Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
    }

    public function count(): int
    {
        return count($this->getAllChecks());
    }
}
