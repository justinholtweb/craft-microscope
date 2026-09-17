<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Finding;

/**
 * OPcache: on, big enough, and holding all of Craft.
 *
 * The highest-value check in the plugin. Craft is a large codebase — several thousand PHP files
 * before a single plugin is installed — and without OPcache every one of them is read from disk,
 * parsed and compiled on every request. Turning it on is frequently the difference between a
 * 400 ms response and a 60 ms one, and it is a two-line config change.
 *
 * The SAPI caveat cuts this check in half. From the CLI, everything read here describes the CLI
 * runtime, which is usually *not* the one serving the site. "OPcache is off here" is still worth
 * saying from the console, with the note that `opcache.enable_cli` is a different directive from
 * `opcache.enable` — but the *tuning* analysis is not. Cache size, file slots, hit rate and
 * restarts all belong to the process running the scan, and a console binary's numbers say nothing
 * about PHP-FPM's, so outside a web SAPI they're left unreported rather than guessed at. The scan
 * records which SAPI it came from either way.
 */
class OpcacheCheck extends BaseCheck
{
    private const RECOMMENDED_MEMORY = 192 * 1024 * 1024;
    private const RECOMMENDED_FILES = 20000;
    private const RECOMMENDED_INTERNED = 16 * 1024 * 1024;

    /**
     * Statistics below this many hits mean the cache hasn't warmed up, and its hit rate says more
     * about how recently PHP-FPM restarted than about how it's configured.
     */
    private const WARMED_UP_HITS = 10000;

    public function handle(): string
    {
        return 'opcache';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'OPcache');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks that PHP’s bytecode cache is enabled and large enough to hold Craft, its plugins and your templates without evicting them.');
    }

    public function run(CheckContext $context): array
    {
        $php = $context->php;

        if (!$php->opcacheEnabled()) {
            return [$this->opcacheDisabled($context)];
        }

        if (!$php->isWebSapi()) {
            return [$this->tuningNotMeasurable($context)];
        }

        $findings = [];
        $memory = $php->opcacheMemory();
        $files = $php->opcacheMaxFiles();
        $cached = $php->opcacheCachedScripts();
        $maxKeys = $php->opcacheMaxCachedKeys();

        // --- size ---------------------------------------------------------
        if ($memory !== null && $memory < self::RECOMMENDED_MEMORY) {
            $ratio = $memory / self::RECOMMENDED_MEMORY;

            $findings[] = $this->finding($this->severityForRatio($ratio), [
                'title' => Craft::t('microscope', 'OPcache memory is smaller than Craft needs'),
                'summary' => Craft::t('microscope', 'OPcache has {observed} to work with. Craft plus a typical set of plugins compiles to more than that, so scripts get evicted and recompiled continuously.', [
                    'observed' => Bytes::format($memory),
                ]),
                'observed' => Bytes::format($memory),
                'expected' => Bytes::format(self::RECOMMENDED_MEMORY) . ' or more',
                'target' => 'opcache.memory_consumption',
                'impact' => Craft::t('microscope', 'When the cache fills, OPcache stops caching new files and eventually restarts, throwing away every compiled script at once. The symptom is a site that is fast most of the time and periodically, inexplicably slow.'),
                'remediation' => $this->sizingInstructions(),
                'docsUrl' => 'https://www.php.net/manual/en/opcache.configuration.php',
            ]);
        }

        // --- file slots ---------------------------------------------------
        if ($files !== null && $files < self::RECOMMENDED_FILES) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'OPcache can’t hold enough files'),
                'summary' => Craft::t('microscope', 'The cache has room for {files} scripts. A Craft install with a few plugins routinely exceeds that.', [
                    'files' => number_format($files),
                ]),
                'observed' => number_format($files) . ' files',
                'expected' => number_format(self::RECOMMENDED_FILES) . '+',
                'target' => 'opcache.max_accelerated_files',
                'impact' => Craft::t('microscope', 'Once every slot is taken, further files are compiled on every request as though OPcache weren’t there. Because the files that miss out are whichever ones loaded last, the effect is unpredictable and hard to attribute.'),
                'remediation' => $this->sizingInstructions(),
            ]);
        }

        // --- actually full? -----------------------------------------------
        if ($cached !== null && $maxKeys !== null && $maxKeys > 0 && $cached >= $maxKeys * 0.95) {
            $findings[] = $this->critical([
                'title' => Craft::t('microscope', 'OPcache is full'),
                'summary' => Craft::t('microscope', '{cached} of {max} slots are in use right now. This is not a projection — the cache is full on this server.', [
                    'cached' => number_format($cached),
                    'max' => number_format($maxKeys),
                ]),
                'observed' => number_format($cached) . ' / ' . number_format($maxKeys),
                'expected' => Craft::t('microscope', 'Comfortably below the limit'),
                'target' => 'opcache.max_accelerated_files',
                'impact' => Craft::t('microscope', 'Every script beyond the limit is recompiled on each request. Response times for the affected pages are effectively un-cached.'),
                'remediation' => $this->sizingInstructions(),
            ]);
        }

        // --- restarts ------------------------------------------------------
        $restarts = $php->opcacheRestarts();
        $wasted = $php->opcacheWastedMemory();
        $free = $php->opcacheFreeMemory();

        if ($restarts !== null && $restarts > 0) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'OPcache has restarted since PHP started'),
                'summary' => Craft::t('microscope', 'The cache has been flushed {count} times. Each restart discards every compiled script and the site recompiles from cold.', [
                    'count' => $restarts,
                ]),
                'observed' => $restarts . ' restarts',
                'expected' => '0',
                'target' => 'opcache.restarts',
                'impact' => Craft::t('microscope', 'A restart is a cliff: for a few seconds afterwards every request pays the full compile cost at once, which on a busy site can be enough to exhaust PHP-FPM’s worker pool.'),
                'remediation' => $this->restartInstructions($wasted, $free),
            ]);
        }

        // --- timestamp validation ------------------------------------------
        if ($context->isProduction() && $php->opcacheValidatesTimestamps()) {
            $frequency = $php->opcacheRevalidateFrequency();

            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'OPcache is checking files for changes in production'),
                'summary' => Craft::t('microscope', 'With `validate_timestamps` on, PHP stats every included file to see whether it changed{frequency}.', [
                    'frequency' => $frequency ? Craft::t('microscope', ', at most every {n} seconds', ['n' => $frequency]) : '',
                ]),
                'observed' => 'validate_timestamps=1' . ($frequency !== null ? ", revalidate_freq={$frequency}" : ''),
                'expected' => 'validate_timestamps=0 (with a deploy-time reset)',
                'target' => 'opcache.validate_timestamps',
                'impact' => Craft::t('microscope', 'A stat call per included file per request. On Craft that is thousands of syscalls a request — small individually, meaningful in aggregate, and entirely wasted on a server where the files only change at deploy time.'),
                'remediation' => $this->timestampInstructions(),
            ]);
        }

        // --- hit rate -------------------------------------------------------
        $hitRate = $php->opcacheHitRate();
        $hits = $php->opcacheHits();

        if ($hitRate !== null && $hits !== null && $hits > self::WARMED_UP_HITS && $hitRate < 95.0) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'OPcache hit rate is low'),
                'summary' => Craft::t('microscope', 'Only {rate}% of script loads are being served from the cache.', [
                    'rate' => number_format($hitRate, 1),
                ]),
                'observed' => number_format($hitRate, 1) . '%',
                'expected' => '99%+',
                'target' => 'opcache.hit_rate',
                'impact' => Craft::t('microscope', 'A healthy Craft site sits above 99%. Anything much below that means scripts are being evicted and recompiled — usually because the cache is too small, occasionally because something is generating PHP files at runtime.'),
                'remediation' => $this->sizingInstructions(),
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'OPcache is enabled and correctly sized'),
                Bytes::format($memory) . ($hitRate !== null ? sprintf(', %.1f%% hit rate', $hitRate) : ''),
                Craft::t('microscope', 'Compiled scripts are being served from memory.'),
            );
        }

        // Interned strings is a refinement, not a problem — reported at notice level regardless of
        // everything else, because it's free to fix and helps most on plugin-heavy sites.
        $interned = $php->opcacheInternedStringsBuffer();

        if ($interned !== null && $interned < self::RECOMMENDED_INTERNED) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'OPcache interned strings buffer is small'),
                'summary' => Craft::t('microscope', 'Class, method and constant names are stored once and shared between requests in this buffer. At {observed} it will overflow on a plugin-heavy site.', [
                    'observed' => Bytes::format($interned),
                ]),
                'observed' => Bytes::format($interned),
                'expected' => Bytes::format(self::RECOMMENDED_INTERNED),
                'target' => 'opcache.interned_strings_buffer',
                'impact' => Craft::t('microscope', 'When the buffer is full, duplicate strings are stored per-request instead of shared, costing memory in every worker process.'),
                'remediation' => "Add this to `php.ini` and restart PHP-FPM:\n\n```ini\nopcache.interned_strings_buffer=16\n```\n\nThe value is in megabytes. 16 is comfortable for Craft with a normal plugin set; 32 if you run a lot of them.",
            ]);
        }

        return $findings;
    }

    /**
     * OPcache is on for the SAPI running the scan, but that SAPI isn't the site's.
     *
     * A pass rather than a problem, and deliberately so: nothing has been found wrong, and nothing
     * has been measured either. Reporting a console cache's size or hit rate as the site's is the
     * mistake this exists to avoid.
     */
    private function tuningNotMeasurable(CheckContext $context): Finding
    {
        return $this->pass(
            Craft::t('microscope', 'OPcache is enabled'),
            Craft::t('microscope', 'Enabled ({sapi} SAPI)', ['sapi' => $context->php->sapi()]),
            Craft::t('microscope', 'Its size, file slots, hit rate and restart count weren’t assessed. This scan ran from the command line, which loads its own `php.ini` and keeps its own cache, so none of those numbers would describe the PHP serving the site. Run a scan from the control panel for those.'),
        );
    }

    private function opcacheDisabled(CheckContext $context): Finding
    {
        $sapiNote = $context->php->isWebSapi()
            ? ''
            : Craft::t('microscope', ' This scan ran from the command line, where OPcache is often off even when it is on for web requests — check **Utilities → System Report** in the control panel to confirm.');

        return $this->critical([
            'title' => Craft::t('microscope', 'OPcache is disabled'),
            'summary' => Craft::t('microscope', 'PHP is recompiling Craft from source on every single request.{note}', ['note' => $sapiNote]),
            'observed' => Craft::t('microscope', 'Disabled ({sapi} SAPI)', ['sapi' => $context->php->sapi()]),
            'expected' => Craft::t('microscope', 'Enabled'),
            'target' => 'opcache.enable',
            'impact' => Craft::t('microscope', 'This is almost certainly the largest single performance problem on the site. Craft loads thousands of PHP files per request; without a bytecode cache every one is read from disk, tokenised and compiled before any of your code runs. Enabling it typically cuts response times by half or more.'),
            'remediation' => $this->enableInstructions(),
            'docsUrl' => 'https://craftcms.com/docs/5.x/system/requirements.html',
        ]);
    }

    private function enableInstructions(): string
    {
        return <<<'MD'
Enable OPcache in `php.ini` — usually a file like `/etc/php/8.3/fpm/php.ini`, or a drop-in under
`conf.d/`:

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

Then restart PHP:

```bash
sudo systemctl restart php8.3-fpm     # or: sudo service php-fpm restart
```

Confirm it took effect from the control panel — **Utilities → PHP Info**, search for `opcache` —
rather than from `php -v` on the CLI. The CLI and FPM read different ini files often enough that
checking the wrong one is the usual reason people believe this is already done.

If OPcache isn't installed at all:

```bash
sudo apt install php8.3-opcache        # Debian/Ubuntu
sudo yum install php-opcache           # RHEL/Rocky
```

On managed hosting (cPanel, Plesk, most shared hosts) look for "PHP Extensions" or "Optimisation"
in the control panel — it's almost always available and just switched off.
MD;
    }

    private function sizingInstructions(): string
    {
        return <<<'MD'
Give OPcache enough room for the whole codebase, plus headroom:

```ini
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.interned_strings_buffer=16
```

Restart PHP-FPM afterwards — these are not reloadable at runtime.

To size it deliberately rather than by rule of thumb, count the PHP files that actually get
loaded:

```bash
find . -name '*.php' -not -path './storage/*' | wc -l
```

Set `opcache.max_accelerated_files` above that number. PHP rounds it up to the next prime, so
there's no point being precise — the cost of setting it too high is a slightly larger hash table,
and the cost of setting it too low is recompiling scripts forever.

For `memory_consumption`, check what's actually in use once the site has been running a while
(**Utilities → PHP Info** → `opcache`, or a `phpinfo()` page): if `used_memory` is close to the
total, raise it.
MD;
    }

    private function restartInstructions(?int $wasted, ?int $free): string
    {
        $detail = '';

        if ($wasted !== null && $free !== null) {
            $detail = sprintf(
                "\n\nRight now: **%s wasted**, **%s free**.\n",
                Bytes::format($wasted),
                Bytes::format($free),
            );
        }

        return <<<MD
Restarts have two causes, and the numbers tell you which one you have.{$detail}
**Out of memory.** The cache filled up. Raise `opcache.memory_consumption` — see the sizing
advice above.

**Wasted memory.** Every time a cached file changes on disk, the old compiled copy is abandoned
rather than reclaimed; once wasted memory passes `opcache.max_wasted_percentage` (5% by default)
OPcache restarts itself to clean up. On a production server files should only change at deploy
time, so persistent churn usually means one of:

- Compiled templates or generated PHP being written into a directory OPcache is caching. Craft
  writes compiled Twig to `storage/runtime/compiled_templates` — that's expected and fine, but if
  the site is clearing caches constantly the churn is real.
- A deploy strategy that replaces files in place rather than swapping a symlinked release
  directory. In-place replacement invalidates everything mid-request.

If deploys are the cause, reset the cache deliberately at the end of each deploy instead of
letting it thrash:

```bash
# with cachetool, or any endpoint that calls opcache_reset()
php cachetool.phar opcache:reset --fcgi=127.0.0.1:9000
```
MD;
    }

    private function timestampInstructions(): string
    {
        return <<<'MD'
On a production server where code only changes at deploy time, stop checking:

```ini
opcache.validate_timestamps=0
```

**This has a hard requirement**: you must reset OPcache as part of every deploy, or your changes
will not take effect at all. Add one of these to the end of your deploy script:

```bash
sudo systemctl reload php8.3-fpm                              # simplest, brief worker recycle
php cachetool.phar opcache:reset --fcgi=127.0.0.1:9000        # no restart needed
```

If you'd rather not take on that requirement — and on a site that deploys rarely, the gain is
modest — the middle ground is to keep validation on but check less often:

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=60
```

Changes then take up to a minute to appear, and the stat calls happen once a minute per file
instead of once per request.
MD;
    }
}
