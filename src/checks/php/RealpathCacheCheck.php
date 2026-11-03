<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\checks\RequiresWebSapi;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

/**
 * The realpath cache, which is the setting nobody tunes and Composer-heavy apps care about most.
 *
 * Every `include` of a relative path makes PHP resolve each directory component to a real path —
 * a `stat` per component. Craft plus Composer's autoloader means deeply nested paths, thousands of
 * times per request. PHP caches the results, but the default 4 MB cache is too small to hold them
 * all, so the same paths get resolved again and again.
 *
 * Both directives, and the live usage figure that goes with them, belong to the running process,
 * and a console binary's cache has nothing to say about the one serving requests — so this only
 * runs on a web request. {@see RequiresWebSapi}
 */
class RealpathCacheCheck extends BaseCheck
{
    use RequiresWebSapi;

    private const RECOMMENDED_SIZE = 4096 * 1024;
    private const RECOMMENDED_TTL = 600;

    public function handle(): string
    {
        return 'realpathCache';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Realpath cache');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks the size and lifetime of PHP’s path resolution cache, which Composer-based applications lean on heavily.');
    }

    public function run(CheckContext $context): array
    {
        $php = $context->php;
        $size = $php->realpathCacheSize();
        $ttl = $php->realpathCacheTtl();
        $used = $php->realpathCacheUsed();
        $findings = [];

        if ($size !== null && $size < self::RECOMMENDED_SIZE) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Realpath cache is small'),
                'summary' => Craft::t('microscope', 'At {observed}, the cache can’t hold every path Craft and Composer resolve in a request.', [
                    'observed' => Bytes::format($size),
                ]),
                'observed' => Bytes::format($size),
                'expected' => Bytes::format(self::RECOMMENDED_SIZE) . '+',
                'target' => 'realpath_cache_size',
                'impact' => Craft::t('microscope', 'Path resolution falls back to `stat` syscalls — several per include, thousands per request. It’s invisible in a profiler that only measures PHP, because the time is spent in the kernel, and it hurts most on network storage where a `stat` is a round trip rather than a memory read.'),
                'remediation' => $this->instructions(),
                'docsUrl' => 'https://www.php.net/manual/en/ini.core.php#ini.realpath-cache-size',
            ]);
        }

        if ($ttl !== null && $ttl < self::RECOMMENDED_TTL) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Realpath cache expires quickly'),
                'summary' => Craft::t('microscope', 'Cached paths are discarded after {observed} seconds and have to be resolved again.', ['observed' => $ttl]),
                'observed' => $ttl . 's',
                'expected' => self::RECOMMENDED_TTL . 's+',
                'target' => 'realpath_cache_ttl',
                'impact' => Craft::t('microscope', 'A short TTL means the cache is repeatedly rebuilt for paths that haven’t moved. On a production server the filesystem layout changes at deploy time and not otherwise, so a two-minute expiry is throwing away work for no benefit.'),
                'remediation' => $this->instructions(),
            ]);
        }

        if ($findings === []) {
            $usage = $used !== null && $size !== null
                ? sprintf(' (%s in use, %d%%)', Bytes::format($used), (int)round($used / $size * 100))
                : '';

            $findings[] = $this->pass(
                Craft::t('microscope', 'Realpath cache is well sized'),
                Bytes::format($size) . $usage,
            );
        }

        return $findings;
    }

    private function instructions(): string
    {
        return <<<'MD'
```ini
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

Restart PHP-FPM afterwards.

This is one of the few settings where the ideal value is measurable rather than guessed. Add a
temporary script to your web root:

```php
<?php
echo round(realpath_cache_size() / 1024) . 'K of ' . ini_get('realpath_cache_size');
```

Hit it a few times once the site has been serving traffic, and set `realpath_cache_size`
comfortably above what it reports. Delete the script afterwards.

If the site runs on NFS, EFS or any other network filesystem, treat this as more important than
it looks — every cache miss there is a network round trip rather than a local `stat`, and a
generous realpath cache is one of the largest wins available.
MD;
    }
}
