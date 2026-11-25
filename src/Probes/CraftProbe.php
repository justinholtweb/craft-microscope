<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Probes;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Images;
use CraftCms\Cms\Support\Facades\Path;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use FilesystemIterator;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Craft's own configuration, the Laravel application it runs in, and the shape of the content.
 *
 * **It runs on console requests too.** Scheduled scans run on the queue, with no request and no
 * user, so anything request-scoped is guarded and answers null rather than throwing.
 *
 * **Field layouts are read through the service, not SQL** — layouts are JSON in
 * `fieldlayouts.config`, so nothing layout-shaped can be answered with a query.
 */
class CraftProbe
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function version(): string
    {
        return Cms::version();
    }

    public function edition(): string
    {
        try {
            return Edition::get()->name;
        } catch (Throwable) {
            return 'Solo';
        }
    }

    /** Laravel's environment name (`APP_ENV`), which Craft 6 uses as its own. */
    public function environment(): string
    {
        return (string) app()->environment();
    }

    /**
     * Whether this environment should be judged as production.
     *
     * The environment name is the signal rather than debug mode, because debug mode is one of the
     * things being judged — inferring "this must be dev" from it would make that check unfailable.
     */
    public function isProduction(): bool
    {
        return ! in_array(strtolower($this->environment()), ['dev', 'development', 'local', 'staging', 'test', 'testing'], true);
    }

    public function generalConfig(): GeneralConfig
    {
        return Cms::config();
    }

    /**
     * Whether the app is in debug mode. Craft 6 deprecates `devMode` in favour of Laravel's
     * `app.debug` (`APP_DEBUG`); either being on counts.
     */
    public function devMode(): bool
    {
        return $this->appDebug() || $this->generalConfig()->devMode;
    }

    /** `app.debug` alone, for reporting which setting is responsible. */
    public function appDebug(): bool
    {
        return (bool) config('app.debug');
    }

    public function allowAdminChanges(): bool
    {
        return $this->generalConfig()->allowAdminChanges;
    }

    public function templateCachingEnabled(): bool
    {
        return $this->generalConfig()->enableTemplateCaching;
    }

    /** The default `{% cache %}` duration in seconds, or null when caches never expire on their own. */
    public function cacheDuration(): ?int
    {
        $duration = $this->generalConfig()->cacheDuration;

        return is_numeric($duration) && (int) $duration > 0 ? (int) $duration : null;
    }

    public function runQueueAutomatically(): bool
    {
        return $this->generalConfig()->runQueueAutomatically;
    }

    public function maxRevisions(): ?int
    {
        $max = $this->generalConfig()->maxRevisions;

        return $max === null || $max <= 0 ? null : $max;
    }

    /** How long soft-deleted rows survive before garbage collection removes them, in seconds. */
    public function softDeleteDuration(): ?int
    {
        $duration = $this->generalConfig()->softDeleteDuration;

        return is_numeric($duration) && (int) $duration > 0 ? (int) $duration : null;
    }

    public function optimizeImageFilesize(): bool
    {
        return $this->generalConfig()->optimizeImageFilesize;
    }

    // Laravel services ----------------------------------------------------------------------

    /** The default cache store's name (`config('cache.default')`). */
    public function cacheStore(): string
    {
        return (string) config('cache.default', 'unknown');
    }

    /** The default cache store's driver: `file`, `database`, `redis`, `memcached`, `array`… */
    public function cacheDriver(): string
    {
        return (string) config('cache.stores.'.$this->cacheStore().'.driver', 'unknown');
    }

    public function usesFileCache(): bool
    {
        return $this->cacheDriver() === 'file';
    }

    /** Laravel's session driver: `file`, `cookie`, `database`, `redis`, `array`… */
    public function sessionDriver(): string
    {
        return (string) config('session.driver', 'file');
    }

    public function usesFileSessions(): bool
    {
        return $this->sessionDriver() === 'file';
    }

    /** The default queue connection's name. */
    public function queueConnection(): string
    {
        return (string) config('queue.default', 'sync');
    }

    /** The default queue connection's driver: `sync`, `database`, `redis`, `sqs`, `beanstalkd`… */
    public function queueDriver(): string
    {
        return (string) config('queue.connections.'.$this->queueConnection().'.driver', $this->queueConnection());
    }

    /** The default log channel's name. */
    public function logChannel(): string
    {
        return (string) config('logging.default', 'stack');
    }

    /**
     * Every channel the default one writes to, following `stack` channels, as name => config.
     *
     * @return array<string, array<string, mixed>>
     */
    public function logChannels(): array
    {
        $resolved = [];
        $walk = function (string $name, int $depth) use (&$walk, &$resolved): void {
            $config = config("logging.channels.{$name}");

            if (! is_array($config) || $depth > 5 || isset($resolved[$name])) {
                return;
            }

            if (($config['driver'] ?? null) === 'stack') {
                foreach ((array) ($config['channels'] ?? []) as $child) {
                    $walk((string) $child, $depth + 1);
                }

                return;
            }

            $resolved[$name] = $config;
        };

        $walk($this->logChannel(), 0);

        return $resolved;
    }

    public function configurationIsCached(): bool
    {
        return app()->configurationIsCached();
    }

    public function routesAreCached(): bool
    {
        return app()->routesAreCached();
    }

    public function eventsAreCached(): bool
    {
        return app()->eventsAreCached();
    }

    public function imageDriver(): string
    {
        try {
            return Images::getIsImagick() ? 'Imagick' : 'GD';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    // Project config --------------------------------------------------------------------------

    public function projectConfigPath(): ?string
    {
        try {
            return Path::projectConfig(create: false);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether the project config is being read from (and written to) `config/craft/project/`. */
    public function usesProjectConfigFile(): bool
    {
        try {
            $path = $this->projectConfigPath();

            return app(ProjectConfig::class)->writeYamlAutomatically || ($path !== null && is_dir($path));
        } catch (Throwable) {
            return false;
        }
    }

    /** Bytes the project config occupies as YAML — a proxy for how much every deploy has to apply. */
    public function projectConfigSize(): ?int
    {
        $path = $this->projectConfigPath();

        if ($path === null || ! is_dir($path)) {
            return null;
        }

        $size = 0;

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    // Content schema ----------------------------------------------------------------------------

    /** @return list<FieldInterface> */
    public function fields(): array
    {
        return $this->remember('fields', fn () => array_values(Fields::getAllFields()->all()));
    }

    public function fieldCount(): int
    {
        return count($this->fields());
    }

    /** @return list<FieldLayout> */
    public function fieldLayouts(): array
    {
        return $this->remember('layouts', fn () => array_values(Fields::getAllLayouts()->all()));
    }

    /**
     * Field handles that appear in at least one layout.
     *
     * @return list<string>
     */
    public function usedFieldHandles(): array
    {
        return $this->remember('usedFields', function () {
            $handles = [];

            foreach ($this->fieldLayouts() as $layout) {
                foreach ($layout->getCustomFields() as $field) {
                    $handles[(string) $field->handle] = true;
                }
            }

            return array_keys($handles);
        });
    }

    /**
     * The widest field layouts on the site.
     *
     * @return list<array{name: string, fields: int}>
     */
    public function largestLayouts(int $limit = 10): array
    {
        $layouts = [];

        foreach ($this->fieldLayouts() as $layout) {
            $count = count($layout->getCustomFields());

            if ($count > 0) {
                $layouts[] = ['name' => $this->describeLayout($layout), 'fields' => $count];
            }
        }

        usort($layouts, fn (array $a, array $b) => $b['fields'] <=> $a['fields']);

        return array_slice($layouts, 0, $limit);
    }

    /** A human-readable name for a layout, which Craft doesn't store directly. */
    private function describeLayout(FieldLayout $layout): string
    {
        $type = $layout->type ? substr((string) strrchr($layout->type, '\\'), 1) : 'Layout';

        try {
            $provider = $layout->provider;

            if ($provider !== null && method_exists($provider, 'getUiLabel')) {
                return $type.': '.$provider->getUiLabel();
            }
        } catch (Throwable) {
            // Orphaned layouts can have no provider; the type alone is still useful.
        }

        return $type.' #'.($layout->id ?? 0);
    }

    /** @return list<mixed> */
    public function sections(): array
    {
        return $this->remember('sections', function () {
            try {
                return array_values(Sections::getAllSections()->all());
            } catch (Throwable) {
                return [];
            }
        });
    }

    /** @return list<mixed> */
    public function entryTypes(): array
    {
        return $this->remember('entryTypes', function () {
            try {
                return array_values(EntryTypes::getAllEntryTypes()->all());
            } catch (Throwable) {
                return [];
            }
        });
    }

    public function siteCount(): int
    {
        return Sites::getAllSites()->count();
    }

    public function pluginCount(): int
    {
        return count(Plugins::getAllPlugins());
    }

    public function hasPlugin(string $handle): bool
    {
        return Plugins::isPluginInstalled($handle);
    }

    // Content volume ----------------------------------------------------------------------------

    /** Live, non-draft, non-revision elements. */
    public function elementCount(): int
    {
        return $this->remember('elementCount', fn () => DB::table(Table::ELEMENTS)
            ->whereNull('draftId')->whereNull('revisionId')->whereNull('dateDeleted')->count());
    }

    public function revisionCount(): int
    {
        return $this->remember('revisionCount', fn () => DB::table(Table::ELEMENTS)->whereNotNull('revisionId')->count());
    }

    public function draftCount(): int
    {
        return $this->remember('draftCount', fn () => DB::table(Table::ELEMENTS)->whereNotNull('draftId')->count());
    }

    /** Soft-deleted elements still waiting on garbage collection. */
    public function trashedCount(): int
    {
        return $this->remember('trashedCount', fn () => DB::table(Table::ELEMENTS)->whereNotNull('dateDeleted')->count());
    }

    /**
     * Jobs waiting on the default queue connection, and failed jobs Laravel is keeping. Either is
     * null when the driver can't say (`sync` has no queue to measure).
     *
     * @return array{waiting: int|null, failed: int|null}
     */
    public function queueDepth(): array
    {
        return $this->remember('queue', function () {
            $waiting = null;
            $failed = null;

            try {
                if ($this->queueDriver() !== 'sync') {
                    $waiting = (int) Queue::connection()->size();
                }
            } catch (Throwable) {
                // An unreachable broker, or a driver that can't count.
            }

            try {
                $failer = app('queue.failer');

                if ($failer instanceof CountableFailedJobProvider) {
                    $failed = (int) $failer->count();
                }
            } catch (Throwable) {
                // No failed-jobs table, or no failer bound.
            }

            return ['waiting' => $waiting, 'failed' => $failed];
        });
    }

    /** Best-effort: `fpm` in the SAPI name is conclusive when present. */
    public function usesFpm(): bool
    {
        return str_contains(strtolower(PHP_SAPI), 'fpm');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return T
     */
    private function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $resolver();
        }

        return $this->cache[$key];
    }

    /** @return array<string, mixed> */
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
            'sessionDriver' => $this->sessionDriver(),
            'queueDriver' => $this->queueDriver(),
            'imageDriver' => $this->imageDriver(),
        ];
    }
}
