<?php

namespace justinholtweb\microscope\checks\craft;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * `devMode` left on in production, and the settings that come with it.
 *
 * The most consequential single line in `config/general.php`. It is *supposed* to be on locally,
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
        return Craft::t('microscope', 'Dev mode');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks that development-only settings — dev mode, admin changes, template debugging — are off in production.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];

        if (!$context->isProduction()) {
            return [$this->pass(
                Craft::t('microscope', 'Development settings are appropriate for this environment'),
                Craft::t('microscope', '{env} environment', ['env' => $craft->environment()]),
                Craft::t('microscope', 'Dev mode isn’t checked outside production — locally it should be on.'),
            )];
        }

        if ($craft->devMode()) {
            $findings[] = $this->critical([
                'title' => Craft::t('microscope', 'Dev mode is enabled in production'),
                'summary' => Craft::t('microscope', 'The site is running in the `{env}` environment with `devMode` on.', [
                    'env' => $craft->environment(),
                ]),
                'observed' => 'devMode = true',
                'expected' => 'devMode = false',
                'target' => 'devMode',
                'impact' => Craft::t('microscope', 'Dev mode disables template caching, recompiles Twig on every request, logs every database query with a full stack trace, and loads the debug toolbar. It typically doubles or triples response time on its own. It also exposes file paths, queries and configuration in error messages to anyone who can trigger one.'),
                'remediation' => $this->instructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#devmode',
            ]);
        }

        if ($craft->allowAdminChanges()) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'Admin changes are allowed in production'),
                'summary' => Craft::t('microscope', '`allowAdminChanges` is on, so the schema can be edited directly on the production site.'),
                'observed' => 'allowAdminChanges = true',
                'expected' => 'allowAdminChanges = false',
                'target' => 'allowAdminChanges',
                'impact' => Craft::t('microscope', 'Craft has to keep the project config writable and watch it for changes, which costs on every request. More importantly, a schema change made in production is a change your repository doesn’t have — the next deploy either reverts it or fails to apply cleanly.'),
                'remediation' => "```php\n// config/general.php\nreturn GeneralConfig::create()\n    ->allowAdminChanges(false);\n```\n\nOr in a multi-environment config array, under the `production` key.\n\nWith this off, the control panel hides settings screens that would write to the project config. Schema changes are then made locally, committed, and applied on deploy:\n\n```bash\nphp craft up\n```\n\n`craft up` runs pending migrations and applies project config changes in the right order — it should be part of every deploy script regardless of this setting.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Production settings are correct'),
                Craft::t('microscope', 'devMode off, admin changes locked'),
            );
        }

        return $findings;
    }

    private function instructions(): string
    {
        return <<<'MD'
Turn it off for production. The cleanest way is to key it off the environment rather than editing
it per deploy:

```php
// config/general.php
use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->devMode(App::env('CRAFT_ENVIRONMENT') === 'dev')
    ->allowAdminChanges(App::env('CRAFT_ENVIRONMENT') === 'dev')
    ->allowUpdates(App::env('CRAFT_ENVIRONMENT') === 'dev');
```

Or, if the config is still an array:

```php
return [
    '*' => [
        'devMode' => false,
    ],
    'dev' => [
        'devMode' => true,
    ],
];
```

**Then check `.env`**, because `CRAFT_DEV_MODE` there overrides the config file and is the usual
reason this stays on after somebody "turned it off":

```bash
CRAFT_ENVIRONMENT=production
CRAFT_DEV_MODE=false
```

**Clear the caches afterwards.** Twig templates compiled under dev mode carry debug instrumentation
and will keep it until they're recompiled:

```bash
php craft clear-caches/compiled-templates
php craft clear-caches/data
```

**Confirm** in the control panel — **Utilities → System Report** shows the effective value, which
is the one that matters rather than what any single file says.
MD;
    }
}
