<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * PHP extensions that change how fast Craft can go.
 *
 * Deliberately not a list of Craft's *required* extensions — Craft won't boot without those, so a
 * running site has them by definition. These are the optional ones that unlock a faster path:
 * a shared-memory cache, a better image library, a native intl implementation.
 */
class ExtensionsCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'phpExtensions';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Performance extensions');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Looks for optional PHP extensions that let Craft take a faster path — shared-memory caching, Imagick, and Zstandard compression.');
    }

    public function run(CheckContext $context): array
    {
        $php = $context->php;
        $findings = [];

        // --- a shared-memory cache -----------------------------------------
        $hasApcu = $php->hasExtension('apcu');
        $hasRedis = $php->hasExtension('redis');
        $hasMemcached = $php->hasExtension('memcached');

        if (!$hasApcu && !$hasRedis && !$hasMemcached) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'No in-memory cache extension is available'),
                'summary' => Craft::t('microscope', 'Neither APCu, Redis nor Memcached is installed, so Craft’s data cache has nowhere to live except the filesystem.'),
                'observed' => Craft::t('microscope', 'None installed'),
                'expected' => 'apcu, redis or memcached',
                'target' => 'php.extensions.cache',
                'impact' => Craft::t('microscope', 'Craft caches a great deal — element queries, template caches, the project config, asset transform indexes. On the filesystem each read is an `open`, `read` and `close`; in shared memory it is a pointer dereference. On a busy site the difference is tens of milliseconds per request.'),
                'remediation' => $this->cacheExtensionInstructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/app.html#cache',
            ]);
        } else {
            $available = array_keys(array_filter([
                'APCu' => $hasApcu,
                'Redis' => $hasRedis,
                'Memcached' => $hasMemcached,
            ]));

            $findings[] = $this->pass(
                Craft::t('microscope', 'An in-memory cache extension is available'),
                implode(', ', $available),
            );
        }

        // --- image handling -------------------------------------------------
        if (!$php->hasExtension('imagick')) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Imagick is not installed'),
                'summary' => Craft::t('microscope', 'Craft is falling back to GD for image transforms.'),
                'observed' => 'GD only',
                'expected' => 'imagick',
                'target' => 'php.extensions.imagick',
                'impact' => Craft::t('microscope', 'GD loads an entire decompressed bitmap into PHP’s memory to resize it — a 6000×4000 JPEG is about 96 MB before anything happens. Imagick streams, handles colour profiles and interlacing properly, and produces visibly better output at the same file size. On sites with large uploads, GD is a frequent cause of memory exhaustion during transforms.'),
                'remediation' => "Install the extension and tell Craft to use it:\n\n```bash\nsudo apt install php8.3-imagick     # Debian/Ubuntu\nsudo yum install php-imagick        # RHEL/Rocky\n```\n\n```php\n// config/general.php\n'imageDriver' => 'imagick',\n```\n\nOn managed hosting, Imagick is usually available in the PHP extensions list — it just isn't enabled by default.\n\nIf Imagick isn't an option, cap what GD has to deal with instead:\n\n```php\n// config/general.php\n'maxUploadFileSize' => 1024 * 1024 * 16,\n'transformGifs' => false,\n```",
            ]);
        }

        // --- intl -----------------------------------------------------------
        if (!$php->hasExtension('intl')) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'The intl extension is missing'),
                'summary' => Craft::t('microscope', 'Craft falls back to PHP-level implementations for date, number and currency formatting.'),
                'observed' => Craft::t('microscope', 'Not installed'),
                'expected' => 'intl',
                'target' => 'php.extensions.intl',
                'impact' => Craft::t('microscope', 'The fallbacks are both slower and less correct — locale-aware formatting done in PHP rather than ICU. On a multi-site or non-English install this shows up as wrong date formats as well as slower rendering.'),
                'remediation' => "```bash\nsudo apt install php8.3-intl        # Debian/Ubuntu\nsudo yum install php-intl           # RHEL/Rocky\n```\n\nRestart PHP-FPM afterwards and confirm in **Utilities → System Report**.",
            ]);
        }

        // --- compression -----------------------------------------------------
        if (!$php->hasExtension('zstd') && !$php->hasExtension('lz4') && ($hasRedis || $hasMemcached)) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'No fast compression extension for cache payloads'),
                'summary' => Craft::t('microscope', 'Zstandard and LZ4 both compress cached values fast enough to be worth doing over a network cache.'),
                'observed' => Craft::t('microscope', 'Not installed'),
                'expected' => 'zstd or lz4',
                'target' => 'php.extensions.compression',
                'impact' => Craft::t('microscope', 'With a network-backed cache like Redis, large serialised values spend most of their time on the wire. Compressing them typically cuts payload size by two thirds for a cost measured in microseconds — but only with a fast codec; gzip is slow enough to be a wash.'),
                'remediation' => "```bash\npecl install zstd\n```\n\nThen enable compression on the cache component in `config/app.php`. This is a refinement, not a fix — worth doing on a site whose cache payloads are large, and safe to ignore otherwise.",
            ]);
        }

        return $findings;
    }

    private function cacheExtensionInstructions(): string
    {
        return <<<'MD'
Install one, then point Craft's cache at it.

**Redis** is the right default for anything with more than one web server, or where the cache
should survive a deploy:

```bash
sudo apt install redis-server php8.3-redis
```

```php
// config/app.php
return [
    'components' => [
        'redis' => [
            'class' => yii\redis\Connection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
        'cache' => [
            'class' => yii\redis\Cache::class,
            'redis' => 'redis',
            'keyPrefix' => Craft::$app->id,
        ],
    ],
];
```

`yii\redis\Cache` needs the `yiisoft/yii2-redis` package: `composer require yiisoft/yii2-redis`.

**APCu** is simpler and faster for a single server, at the cost of being per-machine and lost on
restart:

```bash
sudo apt install php8.3-apcu
```

```php
// config/app.php
return [
    'components' => [
        'cache' => [
            'class' => yii\caching\ApcCache::class,
            'useApcu' => true,
            'keyPrefix' => Craft::$app->id,
        ],
    ],
];
```

Whichever you choose, set `keyPrefix` — without it, two Craft installs sharing a cache server will
read each other's entries.
MD;
    }
}
