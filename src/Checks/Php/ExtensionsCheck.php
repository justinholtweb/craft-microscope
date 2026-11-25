<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Php;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

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
        return t('Performance extensions', category: 'microscope');
    }

    public function description(): string
    {
        return t('Looks for optional PHP extensions that let Craft take a faster path — shared-memory caching, Imagick, and Zstandard compression.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $php = $context->php;
        $findings = [];

        // --- a shared-memory cache -----------------------------------------
        $hasApcu = $php->hasExtension('apcu');
        $hasRedis = $php->hasExtension('redis');
        $hasMemcached = $php->hasExtension('memcached');

        if (! $hasApcu && ! $hasRedis && ! $hasMemcached) {
            $findings[] = $this->warning([
                'title' => t('No in-memory cache extension is available', category: 'microscope'),
                'summary' => t('Neither APCu, Redis nor Memcached is installed, so Craft’s data cache has nowhere to live except the filesystem or the database.', category: 'microscope'),
                'observed' => t('None installed', category: 'microscope'),
                'expected' => 'apcu, redis or memcached',
                'target' => 'php.extensions.cache',
                'impact' => t('Craft caches a great deal — element queries, template caches, the project config, asset transform indexes. On the filesystem each read is an `open`, `read` and `close`; in shared memory it is a pointer dereference. On a busy site the difference is tens of milliseconds per request.', category: 'microscope'),
                'remediation' => $this->cacheExtensionInstructions(),
                'docsUrl' => 'https://laravel.com/docs/cache#configuration',
            ]);
        } else {
            $available = array_keys(array_filter([
                'APCu' => $hasApcu,
                'Redis' => $hasRedis,
                'Memcached' => $hasMemcached,
            ]));

            $findings[] = $this->pass(
                t('An in-memory cache extension is available', category: 'microscope'),
                implode(', ', $available),
            );
        }

        // --- image handling -------------------------------------------------
        if (! $php->hasExtension('imagick')) {
            $findings[] = $this->notice([
                'title' => t('Imagick is not installed', category: 'microscope'),
                'summary' => t('Craft is falling back to GD for image transforms.', category: 'microscope'),
                'observed' => 'GD only',
                'expected' => 'imagick',
                'target' => 'php.extensions.imagick',
                'impact' => t('GD loads an entire decompressed bitmap into PHP’s memory to resize it — a 6000×4000 JPEG is about 96 MB before anything happens. Imagick streams, handles colour profiles and interlacing properly, and produces visibly better output at the same file size. On sites with large uploads, GD is a frequent cause of memory exhaustion during transforms.', category: 'microscope'),
                'remediation' => "Install the extension and tell Craft to use it:\n\n```bash\nsudo apt install php8.3-imagick     # Debian/Ubuntu\nsudo yum install php-imagick        # RHEL/Rocky\n```\n\n```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->imageDriver('imagick');\n```\n\nOn managed hosting, Imagick is usually available in the PHP extensions list — it just isn't enabled by default.\n\nIf Imagick isn't an option, cap what GD has to deal with instead:\n\n```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->maxUploadFileSize(1024 * 1024 * 16)\n    ->transformGifs(false);\n```",
            ]);
        }

        // --- intl -----------------------------------------------------------
        if (! $php->hasExtension('intl')) {
            $findings[] = $this->notice([
                'title' => t('The intl extension is missing', category: 'microscope'),
                'summary' => t('Craft falls back to PHP-level implementations for date, number and currency formatting.', category: 'microscope'),
                'observed' => t('Not installed', category: 'microscope'),
                'expected' => 'intl',
                'target' => 'php.extensions.intl',
                'impact' => t('The fallbacks are both slower and less correct — locale-aware formatting done in PHP rather than ICU. On a multi-site or non-English install this shows up as wrong date formats as well as slower rendering.', category: 'microscope'),
                'remediation' => "```bash\nsudo apt install php8.3-intl        # Debian/Ubuntu\nsudo yum install php-intl           # RHEL/Rocky\n```\n\nRestart PHP-FPM afterwards and confirm in **Utilities → System Report**.",
            ]);
        }

        // --- compression -----------------------------------------------------
        if (! $php->hasExtension('zstd') && ! $php->hasExtension('lz4') && ($hasRedis || $hasMemcached)) {
            $findings[] = $this->notice([
                'title' => t('No fast compression extension for cache payloads', category: 'microscope'),
                'summary' => t('Zstandard and LZ4 both compress cached values fast enough to be worth doing over a network cache.', category: 'microscope'),
                'observed' => t('Not installed', category: 'microscope'),
                'expected' => 'zstd or lz4',
                'target' => 'php.extensions.compression',
                'impact' => t('With a network-backed cache like Redis, large serialised values spend most of their time on the wire. Compressing them typically cuts payload size by two thirds for a cost measured in microseconds — but only with a fast codec; gzip is slow enough to be a wash.', category: 'microscope'),
                'remediation' => "```bash\npecl install zstd\n```\n\nThen enable it on the Redis connection in `config/database.php` (`'options' => ['compression' => Redis::COMPRESSION_ZSTD, 'serializer' => Redis::SERIALIZER_IGBINARY]`, phpredis only). This is a refinement, not a fix — worth doing on a site whose cache payloads are large, and safe to ignore otherwise.",
            ]);
        }

        return $findings;
    }

    private function cacheExtensionInstructions(): string
    {
        return <<<'MD'
Install one, then point Laravel's cache (which Craft 6 uses) at it.

**Redis** is the right default for anything with more than one web server, or where the cache
should survive a deploy:

```bash
sudo apt install redis-server php8.4-redis
```

```bash
# .env
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CACHE_PREFIX=example_com_cache_
```

(Without the extension, Laravel can still reach Redis through `composer require predis/predis`
and `REDIS_CLIENT=predis` — slower, but no server packages needed.)

**APCu** is simpler and faster for a single server, at the cost of being per-machine and lost on
restart:

```bash
sudo apt install php8.4-apcu
```

```bash
# .env
CACHE_STORE=apc
```

Whichever you choose, set `CACHE_PREFIX` — without it, two sites sharing a cache server will read
each other's entries. Then `php artisan config:cache` if the configuration is cached.
MD;
    }
}
