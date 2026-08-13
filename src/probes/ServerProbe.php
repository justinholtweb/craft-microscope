<?php

namespace justinholtweb\microscope\probes;

/**
 * The machine underneath the site.
 *
 * This is the only probe that can come back empty, and it does so routinely — shared hosts disable
 * `shell_exec`, containers hide `/proc`, and macOS has never had one. Every accessor returns null
 * rather than guessing, and the checks built on it skip rather than score.
 *
 * Where a fact is available two ways, `/proc` wins over a fork: reading `/proc/cpuinfo` costs
 * nothing and works with `shell_exec` disabled, where `nproc` needs a subprocess.
 */
class ServerProbe
{
    /**
     * @var array<string, mixed> Memoized derived values.
     */
    private array $cache = [];

    public function __construct(private readonly ShellRunner $shell = new ShellRunner())
    {
    }

    public function shell(): ShellRunner
    {
        return $this->shell;
    }

    public function canShellOut(): bool
    {
        return $this->shell->isAvailable();
    }

    public function shellUnavailableReason(): ?string
    {
        return $this->shell->unavailableReason();
    }

    /**
     * Whether *anything* about the host is discoverable.
     *
     * `/proc` alone is enough for the checks that matter most, so this is broader than
     * {@see canShellOut()}.
     */
    public function hasSystemAccess(): bool
    {
        return $this->canShellOut() || $this->shell->readSystemFile('/proc/meminfo') !== null;
    }

    public function operatingSystem(): string
    {
        return php_uname('s') . ' ' . php_uname('r');
    }

    /**
     * The web server banner, e.g. `nginx/1.24.0`.
     *
     * Comes from the SAPI's own environment, so it's accurate under the web SAPI and absent on the
     * CLI — where there is no web server involved in the request to describe.
     */
    public function webServer(): ?string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;

        return is_string($software) && $software !== '' ? $software : null;
    }

    /**
     * The HTTP protocol version this request arrived on.
     */
    public function protocol(): ?string
    {
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? null;

        return is_string($protocol) && $protocol !== '' ? $protocol : null;
    }

    /**
     * CPU cores available to the machine.
     */
    public function cpuCores(): ?int
    {
        return $this->remember('cores', function() {
            $cpuinfo = $this->shell->readSystemFile('/proc/cpuinfo');

            if ($cpuinfo !== null) {
                $count = preg_match_all('/^processor\s*:/mi', $cpuinfo);

                if ($count > 0) {
                    return $count;
                }
            }

            $nproc = $this->shell->run('nproc');

            if ($nproc !== null && is_numeric(trim($nproc))) {
                return (int)trim($nproc);
            }

            // macOS and the BSDs.
            $sysctl = $this->shell->run('sysctl -n hw.ncpu');

            return $sysctl !== null && is_numeric(trim($sysctl)) ? (int)trim($sysctl) : null;
        });
    }

    /**
     * Total physical memory, in bytes.
     */
    public function totalMemory(): ?int
    {
        return $this->remember('memTotal', fn() => $this->meminfoValue('MemTotal'));
    }

    /**
     * Memory the kernel considers available for new work — not the same as "free".
     *
     * `MemFree` on a healthy Linux box is close to zero because the page cache uses everything
     * spare, and reporting *that* as low memory is the classic misreading. `MemAvailable` is the
     * number that means what people think `free` means.
     */
    public function availableMemory(): ?int
    {
        return $this->remember('memAvailable', function() {
            $available = $this->meminfoValue('MemAvailable');

            if ($available !== null) {
                return $available;
            }

            // Pre-3.14 kernels have no MemAvailable; free + cached is the usual approximation.
            $free = $this->meminfoValue('MemFree');
            $cached = $this->meminfoValue('Cached');

            return $free === null ? null : $free + ($cached ?? 0);
        });
    }

    /**
     * Load averages over 1, 5 and 15 minutes.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function loadAverage(): ?array
    {
        return $this->remember('load', function() {
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();

                if (is_array($load) && count($load) === 3) {
                    return [(float)$load[0], (float)$load[1], (float)$load[2]];
                }
            }

            $loadavg = $this->shell->readSystemFile('/proc/loadavg');

            if ($loadavg === null) {
                return null;
            }

            $parts = preg_split('/\s+/', $loadavg) ?: [];

            return count($parts) >= 3
                ? [(float)$parts[0], (float)$parts[1], (float)$parts[2]]
                : null;
        });
    }

    /**
     * Load average per core, which is the figure that's actually comparable between machines.
     */
    public function loadPerCore(): ?float
    {
        $load = $this->loadAverage();
        $cores = $this->cpuCores();

        if ($load === null || $cores === null || $cores < 1) {
            return null;
        }

        return $load[1] / $cores;
    }

    /**
     * Free space on the filesystem holding the site, in bytes.
     */
    public function freeDiskSpace(?string $path = null): ?int
    {
        $path ??= defined('CRAFT_BASE_PATH') ? CRAFT_BASE_PATH : __DIR__;
        $free = @disk_free_space($path);

        return $free === false ? null : (int)$free;
    }

    public function totalDiskSpace(?string $path = null): ?int
    {
        $path ??= defined('CRAFT_BASE_PATH') ? CRAFT_BASE_PATH : __DIR__;
        $total = @disk_total_space($path);

        return $total === false ? null : (int)$total;
    }

    /**
     * Whether the nginx binary was built with a given module, e.g. `brotli` or `http_v2`.
     *
     * Returns null when nginx isn't reachable from PHP at all, which is the normal case on any
     * host where PHP-FPM runs in a separate container from the web server — so callers must treat
     * null as "unknown" and not as "no".
     */
    public function nginxHasModule(string $module): ?bool
    {
        $build = $this->remember('nginxBuild', function() {
            if (!$this->shell->has('nginx')) {
                return null;
            }

            // nginx writes its configure string to stderr.
            return $this->shell->runCapturingErrors('nginx -V');
        });

        return $build === null ? null : str_contains($build, $module);
    }

    public function nginxVersion(): ?string
    {
        $version = $this->remember('nginxVersion', function() {
            if (!$this->shell->has('nginx')) {
                return null;
            }

            $output = $this->shell->runCapturingErrors('nginx -v');

            return $output !== null && preg_match('#nginx/([\d.]+)#', $output, $m) ? $m[1] : null;
        });

        return is_string($version) ? $version : null;
    }

    /**
     * A value from `/proc/meminfo`, converted from kilobytes to bytes.
     */
    private function meminfoValue(string $key): ?int
    {
        $meminfo = $this->shell->readSystemFile('/proc/meminfo');

        if ($meminfo === null) {
            return null;
        }

        if (!preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB/mi', $meminfo, $matches)) {
            return null;
        }

        return (int)$matches[1] * 1024;
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
            'os' => $this->operatingSystem(),
            'webServer' => $this->webServer(),
            'protocol' => $this->protocol(),
            'cores' => $this->cpuCores(),
            'memory' => $this->totalMemory(),
            'load' => $this->loadAverage(),
            'shellAvailable' => $this->canShellOut(),
        ];
    }
}
