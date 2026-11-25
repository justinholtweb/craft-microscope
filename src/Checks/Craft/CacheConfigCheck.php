<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * Where Craft's caches live (Laravel's default cache store) and whether template caching is on.
 * Sessions are the `sessionDriver` check's business.
 */
class CacheConfigCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'cacheConfig';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Caching', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks Laravel’s cache store, Craft’s template caching and the default cache duration.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $php = $context->php;
        $findings = [];

        // --- template caching --------------------------------------------
        if (! $craft->templateCachingEnabled() && $context->isProduction()) {
            $findings[] = $this->critical([
                'title' => t('Template caching is disabled', category: 'microscope'),
                'summary' => t('`enableTemplateCaching` is off, so every `{% cache %}` tag in the site does nothing.', category: 'microscope'),
                'observed' => 'enableTemplateCaching = false',
                'expected' => 'enableTemplateCaching = true',
                'target' => 'enableTemplateCaching',
                'impact' => t('Every `{% cache %}` block in every template re-renders on every request, including the expensive ones somebody wrapped precisely because they were expensive. The templates look cached; nothing is.', category: 'microscope'),
                'remediation' => "```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->enableTemplateCaching(true);\n```\n\nThis defaults to `true`, so it has been turned off deliberately at some point — very often while debugging a stale-cache problem, and then never turned back on. If a specific block is serving stale content, fix that block (`{% cache using key … %}`, or a shorter `for`) rather than switching caching off site-wide.",
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#enabletemplatecaching',
            ]);
        }

        // --- cache store ----------------------------------------------------
        $driver = $craft->cacheDriver();

        if ($driver === 'array' && $context->isProduction()) {
            $findings[] = $this->critical([
                'title' => t('The cache store forgets everything after each request', category: 'microscope'),
                'summary' => t('The default cache store uses Laravel’s `array` driver, which only lasts for one request.', category: 'microscope'),
                'observed' => sprintf('CACHE_STORE=%s (%s)', $craft->cacheStore(), $driver),
                'expected' => t('Redis, Memcached or the database', category: 'microscope'),
                'target' => 'cache.driver',
                'impact' => t('Nothing Craft caches survives to the next request: element queries, `{% cache %}` output, the project config and transform indexes are all rebuilt every time. Locks and rate limits built on the cache don’t work either, so scheduled tasks can overlap.', category: 'microscope'),
                'remediation' => $this->cacheStoreInstructions($php->hasExtension('redis'), $php->hasExtension('memcached')),
                'docsUrl' => 'https://laravel.com/docs/cache#configuration',
            ]);
        } elseif (in_array($driver, ['file', 'database'], true)) {
            $hasAlternative = $php->hasExtension('redis') || $php->hasExtension('memcached');

            $findings[] = $this->finding($hasAlternative && $driver === 'file' ? 'warning' : 'notice', [
                'title' => $driver === 'file'
                    ? t('Craft is caching to the filesystem', category: 'microscope')
                    : t('Craft is caching in the database', category: 'microscope'),
                'summary' => $hasAlternative
                    ? t('The default cache store uses the `{driver}` driver, even though this server has Redis or Memcached support installed.', ['driver' => $driver], category: 'microscope')
                    : t('The default cache store uses the `{driver}` driver. It works, but it’s the slowest option.', ['driver' => $driver], category: 'microscope'),
                'observed' => sprintf('CACHE_STORE=%s (%s)', $craft->cacheStore(), $driver),
                'expected' => t('Redis or Memcached', category: 'microscope'),
                'target' => 'cache.driver',
                'impact' => $driver === 'file'
                    ? t('Craft caches element queries, template output, transform indexes and the project config here. On the filesystem each read is a syscall and each write contends with every PHP worker; nothing sweeps expired entries, so `storage/framework/cache` only grows — and with more than one web server, each has its own cache, so clearing it on one leaves the others stale.', category: 'microscope')
                    : t('Every cache read and write becomes a query against the same database serving the page, so the cache competes with the content it’s meant to spare, and a busy cache table bloats with expired rows until something prunes them.', category: 'microscope'),
                'remediation' => $this->cacheStoreInstructions($php->hasExtension('redis'), $php->hasExtension('memcached')),
                'docsUrl' => 'https://laravel.com/docs/cache#configuration',
            ]);
        }

        // --- cache duration ----------------------------------------------------
        $duration = $craft->cacheDuration();

        if ($duration !== null && $duration < 3600 && $context->isProduction()) {
            $findings[] = $this->notice([
                'title' => t('Default cache duration is short', category: 'microscope'),
                'summary' => t('Caches without an explicit duration expire after {n} seconds.', ['n' => $duration], category: 'microscope'),
                'observed' => $duration.'s',
                'expected' => t('An hour or more', category: 'microscope'),
                'target' => 'cacheDuration',
                'impact' => t('Craft invalidates element-dependent caches on save, so a short expiry buys nothing in freshness and costs a full re-render every few minutes on pages nobody has edited.', category: 'microscope'),
                'remediation' => "```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->cacheDuration(86400); // a day\n```\n\nOr `0`, which means \"until something invalidates it\" — safe precisely because Craft tracks which elements each cache depends on and clears it when they change.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Caching is well configured', category: 'microscope'),
                sprintf('%s (%s)', $craft->cacheStore(), $driver),
            );
        }

        return $findings;
    }

    private function cacheStoreInstructions(bool $hasRedis, bool $hasMemcached): string
    {
        $preface = match (true) {
            $hasRedis => 'The `redis` extension is already installed on this server, so this is a configuration change only.',
            $hasMemcached => 'The `memcached` extension is already installed on this server, so this is a configuration change only.',
            default => 'No Redis or Memcached extension is installed yet — see the PHP extensions finding in this report. (Laravel can also talk to Redis through the `predis/predis` package, with no extension.)',
        };

        return <<<MD
{$preface}

Craft 6 uses Laravel's cache, so the store is chosen in `.env`:

```bash
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
# Keep each site's keys apart when several share one Redis:
CACHE_PREFIX=example_com_cache_
```

Redis is the right choice for multiple web servers, or where the cache should survive a deploy.
`config/cache.php` and `config/database.php` (the `redis` connections) hold the details if the
defaults don't fit.

**Two things to get right:**

- **Set `CACHE_PREFIX`** (or `REDIS_PREFIX`) when Redis is shared. Two installs using the same
  database index will otherwise read each other's cache entries, which fails in ways that take a
  long time to diagnose.
- **Use a separate Redis database** for the cache and for queues/sessions, so `cache:clear` never
  flushes queued jobs or logs everybody out.

After switching, rebuild the cached configuration and clear what's left behind:

```bash
php artisan config:cache
php artisan craft:clear-caches:data
```
MD;
    }
}
