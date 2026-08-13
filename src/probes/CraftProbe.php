<?php

namespace justinholtweb\microscope\probes;

use Craft;
use craft\config\GeneralConfig;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\App;
use Throwable;

/**
 * Craft's own configuration and the shape of the content in it.
 *
 * Two things worth knowing about this probe:
 *
 * **It runs on console requests too.** Scheduled scans happen on the queue, where there is no
 * session component, no request, and no logged-in user. Every accessor that touches something
 * request-scoped is guarded, and returns null rather than throwing.
 *
 * **Field layouts are read through the service, not SQL.** Craft 5 dropped the `fieldlayoutfields`
 * table — layouts are JSON in `fieldlayouts.config` — so nothing layout-shaped can be answered in
 * a query. The service call is expensive enough to memoize and cheap enough to do once per scan.
 */
class CraftProbe
{
    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    public function version(): string
    {
        return Craft::$app->getVersion();
    }

    public function edition(): string
    {
        return Craft::$app->edition->name ?? 'Solo';
    }

    public function environment(): string
    {
        return (string)Craft::$app->env;
    }

    /**
     * Whether this environment should be judged as production.
     *
     * The environment name is the signal rather than `devMode`, because `devMode` is one of the
     * things being judged — inferring "this must be dev" from it would make the check that reports
     * it impossible to fail.
     */
    public function isProduction(): bool
    {
        $environment = strtolower($this->environment());

        return !in_array($environment, ['dev', 'development', 'local', 'staging', 'test', 'testing'], true);
    }

    public function generalConfig(): GeneralConfig
    {
        return Craft::$app->getConfig()->getGeneral();
    }

    public function devMode(): bool
    {
        return (bool)$this->generalConfig()->devMode;
    }

    public function allowAdminChanges(): bool
    {
        return (bool)$this->generalConfig()->allowAdminChanges;
    }

    public function templateCachingEnabled(): bool
    {
        return (bool)$this->generalConfig()->enableTemplateCaching;
    }

    /**
     * The default `{% cache %}` duration in seconds, or null when caches never expire on their own.
     */
    public function cacheDuration(): ?int
    {
        $duration = $this->generalConfig()->cacheDuration;

        return $duration === null || $duration === 0 ? null : (int)$duration;
    }

    public function runQueueAutomatically(): bool
    {
        return (bool)$this->generalConfig()->runQueueAutomatically;
    }

    public function generateTransformsBeforePageLoad(): bool
    {
        return (bool)$this->generalConfig()->generateTransformsBeforePageLoad;
    }

    public function maxRevisions(): ?int
    {
        $max = $this->generalConfig()->maxRevisions;

        return $max === null ? null : (int)$max;
    }

    /**
     * How long soft-deleted rows survive before garbage collection removes them, in seconds.
     */
    public function softDeleteDuration(): ?int
    {
        $duration = $this->generalConfig()->softDeleteDuration;

        return $duration === 0 || $duration === null ? null : (int)$duration;
    }

    public function optimizeImageFilesize(): bool
    {
        return (bool)$this->generalConfig()->optimizeImageFilesize;
    }

    /**
     * The class backing Craft's data cache — `yii\caching\FileCache` unless the site has changed it.
     */
    public function cacheDriver(): string
    {
        try {
            return get_class(Craft::$app->getCache());
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function usesFileCache(): bool
    {
        return str_contains(strtolower($this->cacheDriver()), 'filecache');
    }

    /**
     * Whether sessions are being stored by PHP's own file handler.
     *
     * Only knowable on a web request — the console app has no session component at all, so this
     * returns null there rather than reporting a default that isn't in use.
     */
    public function usesFileSessions(): ?bool
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        try {
            $session = Craft::$app->getSession();

            return !$session->getUseCustomStorage();
        } catch (Throwable) {
            return null;
        }
    }

    public function imageDriver(): string
    {
        try {
            return Craft::$app->getImages()->getIsImagick() ? 'Imagick' : 'GD';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Whether the project config is being read from (and written to) `config/project/`.
     */
    public function usesProjectConfigFile(): bool
    {
        try {
            return Craft::$app->getProjectConfig()->writeYamlAutomatically
                || is_dir(Craft::$app->getPath()->getProjectConfigPath(false));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Bytes the project config occupies as YAML — a decent proxy for how much work every deploy
     * and every `project-config/apply` has to do.
     */
    public function projectConfigSize(): ?int
    {
        try {
            $path = Craft::$app->getPath()->getProjectConfigPath(false);
        } catch (Throwable) {
            return null;
        }

        if (!is_dir($path)) {
            return null;
        }

        $size = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    // ---------------------------------------------------------------------
    // Content schema
    // ---------------------------------------------------------------------

    /**
     * @return \craft\base\FieldInterface[]
     */
    public function fields(): array
    {
        return $this->remember('fields', fn() => Craft::$app->getFields()->getAllFields());
    }

    public function fieldCount(): int
    {
        return count($this->fields());
    }

    /**
     * Every field layout on the site.
     *
     * @return \craft\models\FieldLayout[]
     */
    public function fieldLayouts(): array
    {
        return $this->remember('layouts', fn() => Craft::$app->getFields()->getAllLayouts());
    }

    /**
     * Field handles that appear in at least one layout.
     *
     * @return string[]
     */
    public function usedFieldHandles(): array
    {
        return $this->remember('usedFields', function() {
            $handles = [];

            foreach ($this->fieldLayouts() as $layout) {
                foreach ($layout->getCustomFields() as $field) {
                    $handles[$field->handle] = true;
                }
            }

            return array_keys($handles);
        });
    }

    /**
     * The widest field layouts on the site, as `[label, fieldCount]` pairs.
     *
     * @return array<int, array{name: string, fields: int}>
     */
    public function largestLayouts(int $limit = 10): array
    {
        $layouts = [];

        foreach ($this->fieldLayouts() as $layout) {
            $count = count($layout->getCustomFields());

            if ($count === 0) {
                continue;
            }

            $layouts[] = [
                'name' => $this->describeLayout($layout),
                'fields' => $count,
            ];
        }

        usort($layouts, fn(array $a, array $b) => $b['fields'] <=> $a['fields']);

        return array_slice($layouts, 0, $limit);
    }

    /**
     * A human-readable name for a layout, which Craft doesn't store directly.
     */
    private function describeLayout(\craft\models\FieldLayout $layout): string
    {
        $type = $layout->type ? substr((string)strrchr($layout->type, '\\'), 1) : 'Layout';

        try {
            $provider = $layout->provider;

            if ($provider !== null && method_exists($provider, 'getUiLabel')) {
                return $type . ': ' . $provider->getUiLabel();
            }
        } catch (Throwable) {
            // Providers can be missing for orphaned layouts; the type alone is still useful.
        }

        return $type . ' #' . ($layout->id ?? 0);
    }

    /**
     * @return \craft\models\Section[]
     */
    public function sections(): array
    {
        return $this->remember('sections', function() {
            try {
                return Craft::$app->getEntries()->getAllSections();
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @return \craft\models\EntryType[]
     */
    public function entryTypes(): array
    {
        return $this->remember('entryTypes', function() {
            try {
                return Craft::$app->getEntries()->getAllEntryTypes();
            } catch (Throwable) {
                return [];
            }
        });
    }

    public function siteCount(): int
    {
        return count(Craft::$app->getSites()->getAllSites());
    }

    /**
     * @return array<string, int>
     */
    public function pluginHandles(): array
    {
        $plugins = [];

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $plugins[$plugin->handle] = 1;
        }

        return $plugins;
    }

    public function pluginCount(): int
    {
        return count(Craft::$app->getPlugins()->getAllPlugins());
    }

    public function hasPlugin(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled($handle);
    }

    // ---------------------------------------------------------------------
    // Content volume
    // ---------------------------------------------------------------------

    /**
     * Live, non-draft, non-revision elements.
     */
    public function elementCount(): int
    {
        return $this->remember('elementCount', fn() => (int)(new Query())
            ->from(Table::ELEMENTS)
            ->where(['draftId' => null, 'revisionId' => null, 'dateDeleted' => null])
            ->count());
    }

    public function revisionCount(): int
    {
        return $this->remember('revisionCount', fn() => (int)(new Query())
            ->from(Table::ELEMENTS)
            ->where(['not', ['revisionId' => null]])
            ->count());
    }

    public function draftCount(): int
    {
        return $this->remember('draftCount', fn() => (int)(new Query())
            ->from(Table::ELEMENTS)
            ->where(['not', ['draftId' => null]])
            ->count());
    }

    /**
     * Soft-deleted elements still waiting on garbage collection.
     */
    public function trashedCount(): int
    {
        return $this->remember('trashedCount', fn() => (int)(new Query())
            ->from(Table::ELEMENTS)
            ->where(['not', ['dateDeleted' => null]])
            ->count());
    }

    /**
     * How many jobs are sitting in Craft's queue, and how many have failed.
     *
     * @return array{waiting: int, failed: int}
     */
    public function queueDepth(): array
    {
        return $this->remember('queue', function() {
            try {
                return [
                    'waiting' => (int)(new Query())->from(Table::QUEUE)->where(['fail' => false])->count(),
                    'failed' => (int)(new Query())->from(Table::QUEUE)->where(['fail' => true])->count(),
                ];
            } catch (Throwable) {
                return ['waiting' => 0, 'failed' => 0];
            }
        });
    }

    /**
     * Whether PHP is being run behind a process manager that keeps it warm.
     *
     * Best-effort: there is no reliable API for this, but `php-fpm` in the SAPI name is
     * conclusive when present.
     */
    public function usesFpm(): bool
    {
        return str_contains(strtolower(PHP_SAPI), 'fpm');
    }

    public function isInDebugMode(): bool
    {
        return App::devMode();
    }

    /**
     * @template T
     * @param callable(): T $resolver
     * @return T
     */
    private function remember(string $key, callable $resolver): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $resolver();
        }

        return $this->cache[$key];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'version' => $this->version(),
            'edition' => $this->edition(),
            'environment' => $this->environment(),
            'devMode' => $this->devMode(),
            'sites' => $this->siteCount(),
            'plugins' => $this->pluginCount(),
            'fields' => $this->fieldCount(),
            'elements' => $this->elementCount(),
            'cacheDriver' => $this->cacheDriver(),
            'imageDriver' => $this->imageDriver(),
        ];
    }
}
