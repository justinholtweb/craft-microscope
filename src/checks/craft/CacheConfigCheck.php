<?php

namespace justinholtweb\microscope\checks\craft;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * Where Craft's caches live and whether template caching is on at all.
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
        return Craft::t('microscope', 'Caching');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks the cache driver, template caching and session storage.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $php = $context->php;
        $findings = [];

        // --- template caching --------------------------------------------
        if (!$craft->templateCachingEnabled() && $context->isProduction()) {
            $findings[] = $this->critical([
                'title' => Craft::t('microscope', 'Template caching is disabled'),
                'summary' => Craft::t('microscope', '`enableTemplateCaching` is off, so every `{% cache %}` tag in the site does nothing.'),
                'observed' => 'enableTemplateCaching = false',
                'expected' => 'enableTemplateCaching = true',
                'target' => 'enableTemplateCaching',
                'impact' => Craft::t('microscope', 'Every `{% cache %}` block in every template re-renders on every request, including the expensive ones somebody wrapped precisely because they were expensive. The templates look cached; nothing is.'),
                'remediation' => "```php\n// config/general.php\n'enableTemplateCaching' => true,\n```\n\nThis defaults to `true`, so it has been turned off deliberately at some point — very often while debugging a stale-cache problem, and then never turned back on. Check `.env` for `CRAFT_ENABLE_TEMPLATE_CACHING` as well.\n\nIf it was disabled because caches weren't invalidating properly, the underlying cause is usually a `{% cache %}` block whose content doesn't depend on any element — Craft invalidates by element, so a block that queries nothing has nothing to invalidate on. Give those an explicit `for` duration instead:\n\n```twig\n{% cache for 1 hour %}…{% endcache %}\n```",
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#enabletemplatecaching',
            ]);
        }

        // --- cache driver ---------------------------------------------------
        if ($craft->usesFileCache()) {
            $hasAlternative = $php->hasExtension('redis') || $php->hasExtension('apcu') || $php->hasExtension('memcached');

            $findings[] = $this->finding($hasAlternative ? 'warning' : 'notice', [
                'title' => Craft::t('microscope', 'Craft is caching to the filesystem'),
                'summary' => $hasAlternative
                    ? Craft::t('microscope', 'The data cache uses files, even though this server has a shared-memory cache available.')
                    : Craft::t('microscope', 'The data cache uses files, which is Craft’s default but the slowest option.'),
                'observed' => $craft->cacheDriver(),
                'expected' => Craft::t('microscope', 'Redis, APCu or Memcached'),
                'target' => 'cache.driver',
                'impact' => Craft::t('microscope', 'Craft caches element queries, template output, asset transform indexes and the project config here. On the filesystem each read is a syscall and each write contends with every PHP worker; there is also no expiry sweep, so `storage/runtime/cache` accumulates files until something clears it. On a multi-server setup file caching is worse than slow — each server has its own, so they disagree.'),
                'remediation' => $this->cacheDriverInstructions($php->hasExtension('redis'), $php->hasExtension('apcu')),
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/app.html#cache',
            ]);
        }

        // --- sessions ---------------------------------------------------------
        $fileSessions = $craft->usesFileSessions();

        if ($fileSessions === true && ($php->hasExtension('redis') || $php->hasExtension('memcached'))) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Sessions are stored as files'),
                'summary' => Craft::t('microscope', 'PHP’s default file session handler is in use, though a shared-memory store is available.'),
                'observed' => Craft::t('microscope', 'PHP file sessions'),
                'expected' => Craft::t('microscope', 'Redis or Memcached sessions'),
                'target' => 'session.driver',
                'impact' => Craft::t('microscope', 'File sessions take an exclusive lock on the session file for the duration of each request, so two simultaneous requests from the same user serialise — noticeable in the control panel, where the page and its Ajax calls fire together. They also can’t be shared between servers.'),
                'remediation' => "```php\n// config/app.php\nreturn [\n    'components' => [\n        'session' => function() {\n            \$config = craft\\helpers\\App::sessionConfig();\n            \$config['class'] = yii\\redis\\Session::class;\n            \$config['redis'] = 'redis';\n            return Craft::createObject(\$config);\n        },\n    ],\n];\n```\n\nStart from `App::sessionConfig()` rather than defining the component from scratch — it carries Craft's own cookie settings, and replacing it wholesale is how people accidentally break \"stay logged in\".",
            ]);
        }

        // --- cache duration ----------------------------------------------------
        $duration = $craft->cacheDuration();

        if ($duration !== null && $duration < 3600 && $context->isProduction()) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Default cache duration is short'),
                'summary' => Craft::t('microscope', 'Caches without an explicit duration expire after {n} seconds.', ['n' => $duration]),
                'observed' => $duration . 's',
                'expected' => Craft::t('microscope', 'An hour or more'),
                'target' => 'cacheDuration',
                'impact' => Craft::t('microscope', 'Craft invalidates element-dependent caches on save, so a short expiry buys nothing in freshness and costs a full re-render every few minutes on pages nobody has edited.'),
                'remediation' => "```php\n// config/general.php\n'cacheDuration' => 86400, // a day\n```\n\nOr `null`, which means \"until something invalidates it\" — safe precisely because Craft tracks which elements each cache depends on and clears it when they change.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Caching is well configured'),
                $craft->cacheDriver(),
            );
        }

        return $findings;
    }

    private function cacheDriverInstructions(bool $hasRedis, bool $hasApcu): string
    {
        $preface = match (true) {
            $hasRedis => "The `redis` extension is already installed on this server, so this is a config change only.",
            $hasApcu => "The `apcu` extension is already installed on this server, so this is a config change only.",
            default => "No shared-memory cache extension is installed yet — see the PHP extensions finding in this report for how to add one.",
        };

        return <<<MD
{$preface}

**Redis** — the right choice for multiple servers, or where the cache should survive a deploy:

```php
// config/app.php
return [
    'components' => [
        'redis' => [
            'class' => yii\redis\Connection::class,
            'hostname' => App::env('REDIS_HOSTNAME') ?: '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
        'cache' => [
            'class' => yii\redis\Cache::class,
            'redis' => 'redis',
            'defaultDuration' => 86400,
            'keyPrefix' => Craft::\$app->id,
        ],
    ],
];
```

Needs `composer require yiisoft/yii2-redis`.

**APCu** — simpler, faster, single-server only, and emptied whenever PHP-FPM restarts:

```php
// config/app.php
return [
    'components' => [
        'cache' => [
            'class' => yii\caching\ApcCache::class,
            'useApcu' => true,
            'defaultDuration' => 86400,
            'keyPrefix' => Craft::\$app->id,
        ],
    ],
];
```

**Two things to get right either way:**

- **Set `keyPrefix`.** Two Craft installs sharing one Redis will otherwise read each other's cache
  entries, which fails in ways that take a long time to diagnose.
- **APCu is per-process-pool.** The CLI has its own, so `php craft clear-caches/data` from the
  console will *not* clear the web cache. Redis doesn't have this problem, which is a real
  argument for it on any site where deploys clear caches from a script.

After switching, clear what's left behind:

```bash
php craft clear-caches/data
rm -rf storage/runtime/cache/*
```
MD;
    }
}
