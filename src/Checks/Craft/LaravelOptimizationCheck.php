<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * Whether Laravel's bootstrap caches are built: configuration, routes and events.
 *
 * Craft 6 boots as a Laravel application, and without these every request re-reads every config
 * file, re-registers every route and re-discovers every event listener before Craft does anything.
 * `php artisan optimize` builds all of them in one step; it belongs in every production deploy.
 *
 * Only judged in production: locally the caches would hide config edits, which is worse.
 */
class LaravelOptimizationCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'laravelOptimization';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Laravel bootstrap caches', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks that the configuration, route and event caches are built in production (`php artisan optimize`).', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;

        if (! $context->isProduction()) {
            return [$this->pass(
                t('Bootstrap caches aren’t expected in this environment', category: 'microscope'),
                t('{env} environment', ['env' => $craft->environment()], category: 'microscope'),
                t('Outside production the caches would hide config and route edits, so they aren’t checked.', category: 'microscope'),
            )];
        }

        $missing = array_keys(array_filter([
            'config' => ! $craft->configurationIsCached(),
            'routes' => ! $craft->routesAreCached(),
            'events' => ! $craft->eventsAreCached(),
        ]));

        if ($missing === []) {
            return [$this->pass(
                t('Configuration, routes and events are cached', category: 'microscope'),
                'config ✓ routes ✓ events ✓',
            )];
        }

        $configMissing = in_array('config', $missing, true);

        return [$this->finding($configMissing ? 'warning' : 'notice', [
            'title' => $configMissing
                ? t('Laravel’s configuration isn’t cached', category: 'microscope')
                : t('Some of Laravel’s bootstrap caches aren’t built', category: 'microscope'),
            'summary' => t('Not cached: {missing}. Every request rebuilds them from scratch.', ['missing' => implode(', ', $missing)], category: 'microscope'),
            'observed' => t('Missing: {missing}', ['missing' => implode(', ', $missing)], category: 'microscope'),
            'expected' => t('config, routes and events cached', category: 'microscope'),
            'target' => 'laravel.optimize',
            'impact' => t('Without the config cache, every request loads and merges every file in `config/` and parses `.env`; without the route and event caches, every route is registered and every listener discovered again. On a typical Craft 6 install that’s several milliseconds of pure bootstrap on every request — more with many plugins — before any content is touched.', category: 'microscope'),
            'remediation' => $this->instructions(),
            'docsUrl' => 'https://laravel.com/docs/deployment#optimization',
        ])];
    }

    private function instructions(): string
    {
        return <<<'MD'
Build them on every deploy, after `composer install` and before the new code takes traffic:

```bash
php artisan optimize
```

That runs `config:cache`, `route:cache`, `event:cache` and `view:cache` together. Add it to the
deploy script next to `php artisan craft:up`.

**Two things change once the config is cached:**

- **`env()` returns null outside `config/` files.** Code (or a plugin) calling `env('X')` directly
  stops seeing `.env`. Read values through `config()` instead, and put any `env()` calls in a
  config file.
- **Edits to `.env` or `config/` need the cache rebuilt** — `php artisan optimize` again, or
  `php artisan optimize:clear` to go back to uncached.

Don't run it locally: it would hide your own config edits until you remembered to clear it.
MD;
    }
}
