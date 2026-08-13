<?php

namespace justinholtweb\microscope\checks\server;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

/**
 * CPU, memory and load on the machine serving the site.
 *
 * The most likely check in the plugin to skip itself. It needs either `/proc` or a shell, and a
 * managed host will often give it neither — in which case it reports that it couldn't see, rather
 * than inferring anything from the silence.
 */
class SystemResourcesCheck extends BaseCheck
{
    /**
     * Load average per core above which the machine is saturated. 1.0 means every core is busy;
     * sustained load above that is a queue forming.
     */
    private const LOAD_WARNING = 0.9;
    private const LOAD_CRITICAL = 1.5;

    /**
     * Below this share of memory still available, the machine is about to start swapping.
     */
    private const MEMORY_WARNING = 0.15;

    public function handle(): string
    {
        return 'systemResources';
    }

    public function category(): string
    {
        return Category::SERVER;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'System resources');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Reads CPU count, available memory and load average from the host, where the environment allows it.');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->settings->allowShellProbes && $context->server->hasSystemAccess();
    }

    public function unavailableReason(CheckContext $context): string
    {
        if (!$context->settings->allowShellProbes) {
            return Craft::t('microscope', 'Shell probes are turned off in Microscope’s settings.');
        }

        $reason = $context->server->shellUnavailableReason();

        return $reason !== null
            ? Craft::t('microscope', 'The host doesn’t allow system inspection: {reason}. This is normal on shared hosting.', ['reason' => $reason])
            : Craft::t('microscope', 'This host doesn’t expose system metrics to PHP.');
    }

    public function run(CheckContext $context): array
    {
        $server = $context->server;
        $findings = [];

        $cores = $server->cpuCores();
        $totalMemory = $server->totalMemory();
        $availableMemory = $server->availableMemory();
        $load = $server->loadAverage();
        $loadPerCore = $server->loadPerCore();

        // --- load ----------------------------------------------------------
        if ($loadPerCore !== null && $load !== null) {
            if ($loadPerCore >= self::LOAD_WARNING) {
                $severity = $loadPerCore >= self::LOAD_CRITICAL ? 'critical' : 'warning';

                $findings[] = $this->finding($severity, [
                    'title' => Craft::t('microscope', 'The server is under sustained load'),
                    'summary' => Craft::t('microscope', 'Five-minute load average is {load} across {cores} cores — {perCore} per core.', [
                        'load' => number_format($load[1], 2),
                        'cores' => $cores,
                        'perCore' => number_format($loadPerCore, 2),
                    ]),
                    'observed' => sprintf('%.2f / %.2f / %.2f', $load[0], $load[1], $load[2]),
                    'expected' => Craft::t('microscope', 'Below {n} per core', ['n' => self::LOAD_WARNING]),
                    'target' => 'server.load',
                    'impact' => Craft::t('microscope', 'At or above 1.0 per core every processor is busy and work is queueing. Response times stop tracking how much work a request does and start tracking how long it waited for a core — which is why a saturated server feels slow in a way that profiling individual requests never explains.'),
                    'remediation' => $this->loadInstructions($load, $cores),
                ]);
            }
        }

        // --- memory ---------------------------------------------------------
        if ($totalMemory !== null && $availableMemory !== null && $totalMemory > 0) {
            $share = $availableMemory / $totalMemory;

            if ($share < self::MEMORY_WARNING) {
                $findings[] = $this->warning([
                    'title' => Craft::t('microscope', 'The server is low on memory'),
                    'summary' => Craft::t('microscope', 'Only {available} of {total} is available.', [
                        'available' => Bytes::format($availableMemory),
                        'total' => Bytes::format($totalMemory),
                    ]),
                    'observed' => Bytes::format($availableMemory) . ' / ' . Bytes::format($totalMemory),
                    'expected' => Craft::t('microscope', 'At least 15% available'),
                    'target' => 'server.memory',
                    'impact' => Craft::t('microscope', 'Once memory runs out the kernel starts swapping, and a swapping database server is orders of magnitude slower than a healthy one. If it runs out entirely the OOM killer picks a process — often MySQL, because it is the largest — and the site goes down.'),
                    'remediation' => $this->memoryInstructions($totalMemory),
                ]);
            }
        }

        if ($cores !== null && $cores < 2) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'The server has a single CPU core'),
                'summary' => Craft::t('microscope', 'One core has to serve web requests, the database and any queue work simultaneously.'),
                'observed' => '1 core',
                'expected' => '2+',
                'target' => 'server.cores',
                'impact' => Craft::t('microscope', 'With one core there is no parallelism at all: a queue job running an image transform directly delays every web request, and the database competes with PHP for the same processor.'),
                'remediation' => "Move to an instance with at least two cores — this is the cheapest upgrade available on every cloud host, and on a single-core box it roughly halves the tail latency on its own.\n\nUntil then, keep background work off the critical path:\n\n```php\n// config/general.php\n'runQueueAutomatically' => false,\n```\n\nand run the queue from cron or a supervisor process instead, ideally niced:\n\n```bash\nnice -n 10 php craft queue/listen\n```",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'System resources are healthy'),
                Craft::t('microscope', '{cores} cores, {memory} RAM, load {load}', [
                    'cores' => $cores ?? '?',
                    'memory' => Bytes::format($totalMemory),
                    'load' => $load !== null ? number_format($load[1], 2) : '?',
                ]),
            );
        }

        return $findings;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $load
     */
    private function loadInstructions(array $load, ?int $cores): string
    {
        $trend = $load[0] > $load[2]
            ? 'Load is **rising** — the one-minute average is above the fifteen-minute one, so this is happening now rather than being a leftover from earlier.'
            : 'Load is **falling** — the one-minute average is below the fifteen-minute one, so the peak has passed.';

        $coreCount = $cores ?? 1;

        return <<<MD
{$trend}

**Find out what's using the CPU** before adding any:

```bash
top -bn1 | head -20
ps aux --sort=-%cpu | head -10
```

In a Craft site the answer is usually one of four things:

- **PHP-FPM workers**, meaning real traffic or slow requests holding workers open. Check
  `pm.max_children` against your core count — more workers than cores doesn't increase throughput,
  it just increases the queue.
- **MySQL**, meaning a query problem rather than a capacity problem. The slow query log check in
  this report is where to look next.
- **Image transforms.** These are CPU-bound and can saturate a machine on their own. Set
  `'generateTransformsBeforePageLoad' => false` so they happen outside the request, and move the
  queue to a separate process.
- **The queue.** With `runQueueAutomatically` on, jobs run inside web requests and compete
  directly with visitors.

**Sizing PHP-FPM** for {$coreCount} cores, as a starting point:

```ini
; /etc/php/8.3/fpm/pool.d/www.conf
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
```

`pm.max_children` should be set from available memory divided by average worker size (check with
`ps -ylC php-fpm8.3 --sort:rss`), not from core count — but if it is many times your core count,
requests are queueing inside PHP rather than being served.
MD;
    }

    private function memoryInstructions(int $total): string
    {
        $formatted = Bytes::format($total);

        return <<<MD
This machine has **{$formatted}** total. Find where it's going:

```bash
free -h
ps aux --sort=-%mem | head -10
```

The three consumers on a typical Craft server, in order:

1. **MySQL's InnoDB buffer pool** — a fixed allocation, and deliberately large. Check the
   buffer pool finding in this report; if the pool is bigger than about 60% of RAM on a shared
   machine, it's over-allocated rather than well-tuned.
2. **PHP-FPM workers** — `pm.max_children` × per-worker memory. Measure the real figure:
   ```bash
   ps -ylC php-fpm8.3 --sort:rss | awk '{sum+=$8; n++} END {print sum/n/1024 " MB average"}'
   ```
   Then make sure `max_children × average` leaves room for everything else.
3. **The OS page cache**, which will use everything spare and give it back on demand. This is
   healthy — do not read low "free" memory as a problem. `MemAvailable` (what this check reads) is
   the number that matters.

**Check whether it's already swapping**, which is the point where the symptom becomes severe:

```bash
vmstat 1 5
```

Non-zero `si`/`so` columns mean active swapping. Add memory or reduce allocations; no amount of
tuning elsewhere compensates for a swapping server.
MD;
    }
}
