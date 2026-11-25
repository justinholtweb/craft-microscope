<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * Debug mode (`APP_DEBUG`, or Craft's deprecated `devMode`) left on in production, and the
 * settings that come with it.
 *
 * The most consequential single line in a production `.env`. It is *supposed* to be on locally,
 * so the check only fires when the environment says production — otherwise every developer would
 * see a critical finding on every scan and learn to ignore the report.
 */
class DevModeCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'devMode';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Debug mode', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks that development-only settings — debug mode (`APP_DEBUG`) and admin changes — are off in production.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];

        if (! $context->isProduction()) {
            return [$this->pass(
                t('Development settings are appropriate for this environment', category: 'microscope'),
                t('{env} environment', ['env' => $craft->environment()], category: 'microscope'),
                t('Debug mode isn’t checked outside production — locally it should be on.', category: 'microscope'),
            )];
        }

        if ($craft->devMode()) {
            $setting = $craft->appDebug() ? 'APP_DEBUG' : 'devMode';

            $findings[] = $this->critical([
                'title' => t('Debug mode is enabled in production', category: 'microscope'),
                'summary' => t('The site is running in the `{env}` environment with `{setting}` on.', [
                    'env' => $craft->environment(),
                    'setting' => $setting,
                ], category: 'microscope'),
                'observed' => $craft->appDebug() ? 'APP_DEBUG = true' : 'devMode = true',
                'expected' => 'APP_DEBUG = false',
                'target' => 'devMode',
                'impact' => t('Debug mode recompiles templates more eagerly, records every database query with a stack trace, keeps the debug toolbar’s data, and turns off the caching that assumes nothing changes between requests. It typically doubles or triples response time on its own. It also puts file paths, queries, environment details and configuration into error pages that anyone who can trigger an error will see.', category: 'microscope'),
                'remediation' => $this->instructions(),
                'docsUrl' => 'https://laravel.com/docs/configuration#debug-mode',
            ]);
        }

        if ($craft->allowAdminChanges()) {
            $findings[] = $this->warning([
                'title' => t('Admin changes are allowed in production', category: 'microscope'),
                'summary' => t('`allowAdminChanges` is on, so the schema can be edited directly on the production site.', category: 'microscope'),
                'observed' => 'allowAdminChanges = true',
                'expected' => 'allowAdminChanges = false',
                'target' => 'allowAdminChanges',
                'impact' => t('Craft has to keep the project config writable and watch it for changes, which costs on every request. More importantly, a schema change made in production is a change your repository doesn’t have — the next deploy either reverts it or fails to apply cleanly.', category: 'microscope'),
                'remediation' => "```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->allowAdminChanges(false);\n```\n\nOr from `.env` with `CRAFT_ALLOW_ADMIN_CHANGES=false`.\n\nWith this off, the control panel hides settings screens that would write to the project config. Schema changes are then made locally, committed, and applied on deploy:\n\n```bash\nphp artisan craft:up\n```\n\n`craft:up` runs pending migrations and applies project config changes in the right order — it should be part of every deploy script regardless of this setting.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Production settings are correct', category: 'microscope'),
                t('Debug mode off, admin changes locked', category: 'microscope'),
            );
        }

        return $findings;
    }

    private function instructions(): string
    {
        return <<<'MD'
Craft 6 runs on Laravel, and Laravel's `APP_DEBUG` is the switch: Craft's own `devMode` setting is
deprecated in favour of it. Set it in the production environment's `.env`:

```bash
APP_ENV=production
APP_DEBUG=false
```

**Then check `config/craft/general.php`** for a leftover `devMode(true)` (or `'devMode' => true`)
carried over from Craft 5 — remove it, since `APP_DEBUG` is what should decide this now.

**If the configuration is cached, rebuild it**, or the old value stays in effect:

```bash
php artisan config:cache
```

**Clear compiled templates**, which keep the debug instrumentation they were compiled with:

```bash
php artisan craft:clear-caches:compiled-templates
php artisan craft:clear-caches:data
```

**Confirm** with `php artisan about`, which shows the effective debug value and environment —
that's the one that matters, rather than what any single file says.
MD;
    }
}
