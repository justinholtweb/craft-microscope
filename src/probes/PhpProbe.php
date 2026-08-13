<?php

namespace justinholtweb\microscope\probes;

use justinholtweb\microscope\helpers\Ini;

/**
 * What the PHP runtime will tell us about itself.
 *
 * Everything here is read-only and free — no forks, no queries — so the whole probe runs in
 * microseconds and checks can ask it anything without thinking about cost.
 *
 * One caveat runs through the whole class and is worth stating once: **this reads the PHP that is
 * running the scan.** From the control panel that's the web SAPI; from `php craft microscope/scan`
 * it's the CLI SAPI, which routinely has a different `memory_limit`, a different
 * `max_execution_time` and, very often, no OPcache at all. {@see sapi()} is recorded on every scan
 * for exactly this reason, and the CLI check warns when a scan was taken from a SAPI whose numbers
 * don't describe the site visitors hit.
 */
class PhpProbe
{
    /**
     * @var array<string, mixed>|null Memoized `opcache_get_status()`.
     */
    private ?array $opcacheStatus = null;

    private bool $opcacheStatusRead = false;

    public function version(): string
    {
        return PHP_VERSION;
    }

    public function sapi(): string
    {
        return PHP_SAPI;
    }

    /**
     * Whether the scan is running somewhere whose PHP settings describe real web requests.
     */
    public function isWebSapi(): bool
    {
        return !in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    public function is64Bit(): bool
    {
        return PHP_INT_SIZE === 8;
    }

    public function memoryLimit(): ?int
    {
        return Ini::bytes('memory_limit');
    }

    public function maxExecutionTime(): ?int
    {
        return Ini::int('max_execution_time');
    }

    public function maxInputVars(): ?int
    {
        return Ini::int('max_input_vars');
    }

    public function postMaxSize(): ?int
    {
        return Ini::bytes('post_max_size');
    }

    public function uploadMaxFilesize(): ?int
    {
        return Ini::bytes('upload_max_filesize');
    }

    public function realpathCacheSize(): ?int
    {
        return Ini::bytes('realpath_cache_size');
    }

    public function realpathCacheTtl(): ?int
    {
        return Ini::int('realpath_cache_ttl');
    }

    /**
     * How much of the realpath cache is actually in use.
     *
     * More useful than the configured size on its own: a 4 MB cache that's 90% full is about to
     * start thrashing, and a 16 MB cache that's 5% full is just reserved memory.
     */
    public function realpathCacheUsed(): ?int
    {
        return function_exists('realpath_cache_size') ? (int)realpath_cache_size() : null;
    }

    public function hasExtension(string $name): bool
    {
        return extension_loaded($name);
    }

    /**
     * @return string[]
     */
    public function extensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return $extensions;
    }

    public function extensionVersion(string $name): ?string
    {
        $version = phpversion($name);

        return $version === false ? null : $version;
    }

    // ---------------------------------------------------------------------
    // OPcache
    // ---------------------------------------------------------------------

    public function opcacheEnabled(): bool
    {
        // `opcache.enable` governs the web SAPI and `opcache.enable_cli` the CLI one, and reading
        // the wrong one is how you get a scan that cheerfully reports OPcache is off on a server
        // where it is on for every real request.
        $directive = $this->isWebSapi() ? 'opcache.enable' : 'opcache.enable_cli';

        return Ini::bool($directive) && $this->hasExtension('Zend OPcache');
    }

    public function opcacheMemory(): ?int
    {
        $mb = Ini::int('opcache.memory_consumption');

        return $mb === null ? null : $mb * 1024 * 1024;
    }

    public function opcacheMaxFiles(): ?int
    {
        return Ini::int('opcache.max_accelerated_files');
    }

    public function opcacheInternedStringsBuffer(): ?int
    {
        $mb = Ini::int('opcache.interned_strings_buffer');

        return $mb === null ? null : $mb * 1024 * 1024;
    }

    public function opcacheValidatesTimestamps(): bool
    {
        return Ini::bool('opcache.validate_timestamps');
    }

    public function opcacheRevalidateFrequency(): ?int
    {
        return Ini::int('opcache.revalidate_freq');
    }

    public function opcacheSaveComments(): bool
    {
        return Ini::bool('opcache.save_comments');
    }

    public function opcacheJitBuffer(): ?int
    {
        return Ini::bytes('opcache.jit_buffer_size');
    }

    public function opcacheJitMode(): ?string
    {
        return Ini::get('opcache.jit');
    }

    /**
     * Live OPcache statistics, or null when the extension isn't there or has been restricted.
     *
     * `opcache_get_status()` can be disabled independently of OPcache itself via
     * `opcache.restrict_api`, so a null here means "can't see", not "not running".
     *
     * @return array<string, mixed>|null
     */
    public function opcacheStatus(): ?array
    {
        if ($this->opcacheStatusRead) {
            return $this->opcacheStatus;
        }

        $this->opcacheStatusRead = true;

        if (!function_exists('opcache_get_status')) {
            return $this->opcacheStatus = null;
        }

        // Silenced: with opcache.restrict_api set, this emits a warning on every call, and the
        // null return already tells us everything we need.
        $status = @opcache_get_status(false);

        return $this->opcacheStatus = is_array($status) ? $status : null;
    }

    /**
     * Cache hit rate as a percentage, or null if statistics aren't visible.
     *
     * Only meaningful once the cache has warmed up — a freshly restarted PHP-FPM reports a low
     * rate no matter how well it's configured, so checks pair this with the hit count.
     */
    public function opcacheHitRate(): ?float
    {
        $stats = $this->opcacheStatus()['opcache_statistics'] ?? null;

        if (!is_array($stats) || !isset($stats['opcache_hit_rate'])) {
            return null;
        }

        return (float)$stats['opcache_hit_rate'];
    }

    public function opcacheHits(): ?int
    {
        $stats = $this->opcacheStatus()['opcache_statistics'] ?? null;

        return isset($stats['hits']) ? (int)$stats['hits'] : null;
    }

    public function opcacheCachedScripts(): ?int
    {
        $stats = $this->opcacheStatus()['opcache_statistics'] ?? null;

        return isset($stats['num_cached_scripts']) ? (int)$stats['num_cached_scripts'] : null;
    }

    public function opcacheMaxCachedKeys(): ?int
    {
        $stats = $this->opcacheStatus()['opcache_statistics'] ?? null;

        return isset($stats['max_cached_keys']) ? (int)$stats['max_cached_keys'] : null;
    }

    public function opcacheUsedMemory(): ?int
    {
        $memory = $this->opcacheStatus()['memory_usage'] ?? null;

        return isset($memory['used_memory']) ? (int)$memory['used_memory'] : null;
    }

    public function opcacheFreeMemory(): ?int
    {
        $memory = $this->opcacheStatus()['memory_usage'] ?? null;

        return isset($memory['free_memory']) ? (int)$memory['free_memory'] : null;
    }

    /**
     * Memory OPcache has had to abandon to stale copies of recompiled files.
     *
     * High wasted memory with plenty free is normal churn. High wasted memory with little free
     * means the cache is about to restart itself, which throws away every compiled script at once.
     */
    public function opcacheWastedMemory(): ?int
    {
        $memory = $this->opcacheStatus()['memory_usage'] ?? null;

        return isset($memory['wasted_memory']) ? (int)$memory['wasted_memory'] : null;
    }

    public function opcacheRestarts(): ?int
    {
        $stats = $this->opcacheStatus()['opcache_statistics'] ?? null;

        if (!is_array($stats)) {
            return null;
        }

        return (int)($stats['oom_restarts'] ?? 0)
            + (int)($stats['hash_restarts'] ?? 0)
            + (int)($stats['manual_restarts'] ?? 0);
    }

    /**
     * A snapshot for the report's environment block.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'version' => $this->version(),
            'sapi' => $this->sapi(),
            'memoryLimit' => $this->memoryLimit(),
            'opcache' => $this->opcacheEnabled(),
            'opcacheMemory' => $this->opcacheMemory(),
            'architecture' => $this->is64Bit() ? '64-bit' : '32-bit',
        ];
    }
}
