<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use CraftCms\Cms\Support\Facades\Plugins;
use JustinHoltWeb\Microscope\Checks\CheckInterface;
use JustinHoltWeb\Microscope\Checks\Craft\CacheConfigCheck;
use JustinHoltWeb\Microscope\Checks\Craft\DevModeCheck;
use JustinHoltWeb\Microscope\Checks\Craft\GarbageCollectionCheck;
use JustinHoltWeb\Microscope\Checks\Craft\ImageHandlingCheck;
use JustinHoltWeb\Microscope\Checks\Craft\LaravelOptimizationCheck;
use JustinHoltWeb\Microscope\Checks\Craft\LogLevelCheck;
use JustinHoltWeb\Microscope\Checks\Craft\ProjectConfigCheck;
use JustinHoltWeb\Microscope\Checks\Craft\QueueRunnerCheck;
use JustinHoltWeb\Microscope\Checks\Craft\SessionDriverCheck;
use JustinHoltWeb\Microscope\Checks\Database\ConnectionLimitsCheck;
use JustinHoltWeb\Microscope\Checks\Database\DatabaseVersionCheck;
use JustinHoltWeb\Microscope\Checks\Database\InnodbBufferPoolCheck;
use JustinHoltWeb\Microscope\Checks\Database\QueryCacheCheck;
use JustinHoltWeb\Microscope\Checks\Database\SlowQueryLogCheck;
use JustinHoltWeb\Microscope\Checks\Database\TableBloatCheck;
use JustinHoltWeb\Microscope\Checks\Database\TempTableCheck;
use JustinHoltWeb\Microscope\Checks\Php\ExtensionsCheck;
use JustinHoltWeb\Microscope\Checks\Php\MemoryLimitCheck;
use JustinHoltWeb\Microscope\Checks\Php\OpcacheCheck;
use JustinHoltWeb\Microscope\Checks\Php\PhpVersionCheck;
use JustinHoltWeb\Microscope\Checks\Php\RealpathCacheCheck;
use JustinHoltWeb\Microscope\Checks\Php\RequestLimitsCheck;
use JustinHoltWeb\Microscope\Checks\Schema\ContentVolumeCheck;
use JustinHoltWeb\Microscope\Checks\Schema\FieldLayoutCheck;
use JustinHoltWeb\Microscope\Checks\Schema\FieldUsageCheck;
use JustinHoltWeb\Microscope\Checks\Server\CompressionCheck;
use JustinHoltWeb\Microscope\Checks\Server\DiskSpaceCheck;
use JustinHoltWeb\Microscope\Checks\Server\HttpProtocolCheck;
use JustinHoltWeb\Microscope\Checks\Server\SystemResourcesCheck;
use JustinHoltWeb\Microscope\Checks\Templates\TemplateCheck;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Events\RegisteringChecks;
use JustinHoltWeb\Microscope\Models\Settings;

/**
 * The catalogue of checks.
 *
 * Built once per instance (bind it as a singleton) and extensible through {@see RegisteringChecks}.
 * Settings are read fresh on every call, so a singleton never answers from stale settings.
 */
class Checks
{
    /** @var list<CheckInterface>|null */
    private ?array $checks = null;

    /**
     * Every registered check, in the order they run and are reported — within a category, roughly
     * most consequential first, so a report reads as a priority list before severity sorting.
     *
     * @return list<CheckInterface>
     */
    public function all(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        $event = new RegisteringChecks([
            // PHP
            new OpcacheCheck,
            new PhpVersionCheck,
            new MemoryLimitCheck,
            new RequestLimitsCheck,
            new ExtensionsCheck,
            new RealpathCacheCheck,

            // Database
            new InnodbBufferPoolCheck,
            new DatabaseVersionCheck,
            new TableBloatCheck,
            new TempTableCheck,
            new SlowQueryLogCheck,
            new ConnectionLimitsCheck,
            new QueryCacheCheck,

            // Server
            new SystemResourcesCheck,
            new CompressionCheck,
            new HttpProtocolCheck,
            new DiskSpaceCheck,

            // Craft and Laravel
            new DevModeCheck,
            new LaravelOptimizationCheck,
            new CacheConfigCheck,
            new SessionDriverCheck,
            new QueueRunnerCheck,
            new LogLevelCheck,
            new ImageHandlingCheck,
            new GarbageCollectionCheck,
            new ProjectConfigCheck,

            // Schema
            new FieldLayoutCheck,
            new FieldUsageCheck,
            new ContentVolumeCheck,

            // Templates
            new TemplateCheck,
        ]);

        event($event);

        // Listeners can add anything; keep only real checks, first registration of a handle wins.
        $checks = [];

        foreach ($event->checks as $check) {
            if ($check instanceof CheckInterface && ! isset($checks[$check->handle()])) {
                $checks[$check->handle()] = $check;
            }
        }

        return $this->checks = array_values($checks);
    }

    public function get(string $handle): ?CheckInterface
    {
        foreach ($this->all() as $check) {
            if ($check->handle() === $handle) {
                return $check;
            }
        }

        return null;
    }

    /** @return list<CheckInterface> */
    public function byCategory(string $category): array
    {
        return array_values(array_filter($this->all(), fn (CheckInterface $check) => $check->category() === $category));
    }

    /**
     * The catalogue grouped for the control panel, in {@see Category::all()} order.
     *
     * @return array<string, list<CheckInterface>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach (Category::all() as $category) {
            $checks = $this->byCategory($category);

            if ($checks !== []) {
                $grouped[$category] = $checks;
            }
        }

        return $grouped;
    }

    public function isEnabled(string $handle): bool
    {
        return $this->settings()?->checkIsEnabled($handle) ?? true;
    }

    /**
     * Turns a check on or off and saves the plugin's settings (to project config, so this needs
     * admin changes allowed, like any settings save).
     */
    public function setEnabled(string $handle, bool $enabled): bool
    {
        $plugin = Plugins::getPlugin('microscope');
        $settings = $this->settings();

        if ($plugin === null || $settings === null) {
            return false;
        }

        $disabled = $settings->disabledChecks;

        if ($enabled) {
            $disabled = array_values(array_diff($disabled, [$handle]));
        } elseif (! in_array($handle, $disabled, true)) {
            $disabled[] = $handle;
        }

        return Plugins::savePluginSettings($plugin, ['disabledChecks' => $disabled]);
    }

    public function count(): int
    {
        return count($this->all());
    }

    private function settings(): ?Settings
    {
        $settings = Plugins::getPlugin('microscope')?->getSettings();

        return $settings instanceof Settings ? $settings : null;
    }
}
