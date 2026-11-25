<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Support\Bytes;

use function CraftCms\Cms\t;

/**
 * The project config, which Craft loads and compares on every request.
 */
class ProjectConfigCheck extends BaseCheck
{
    /**
     * Beyond this the config is large enough that parsing and diffing it is measurable.
     */
    private const LARGE = 2 * 1024 * 1024;

    public function handle(): string
    {
        return 'projectConfig';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Project config', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the size of the project config and whether Craft is watching it for changes in production.', category: 'microscope');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->craft->usesProjectConfigFile();
    }

    public function unavailableReason(CheckContext $context): string
    {
        return t('This site doesn’t keep its project config in files, so there is nothing to read or diff.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $size = $context->craft->projectConfigSize();
        $findings = [];

        if ($size !== null && $size > self::LARGE) {
            $findings[] = $this->notice([
                'title' => t('The project config is large', category: 'microscope'),
                'summary' => t('The YAML in `config/craft/project/` totals {size}.', ['size' => Bytes::format($size)], category: 'microscope'),
                'observed' => Bytes::format($size),
                'expected' => t('Under {size}', ['size' => Bytes::format(self::LARGE)], category: 'microscope'),
                'target' => 'projectConfig.size',
                'impact' => t('Craft parses the config and compares it against the stored copy whenever it needs to know whether the two have diverged. On a large config that is real work, and `project-config/apply` on deploy gets slower in proportion.', category: 'microscope'),
                'remediation' => $this->sizeInstructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/system/project-config.html',
            ]);
        }

        if ($context->isProduction() && $context->craft->allowAdminChanges()) {
            // Reported by DevModeCheck as a config problem; here it's specifically about the cost.
            $findings[] = $this->notice([
                'title' => t('Craft is watching the project config for external changes', category: 'microscope'),
                'summary' => t('With `allowAdminChanges` on, Craft checks whether the YAML files have changed on disk.', category: 'microscope'),
                'observed' => 'allowAdminChanges = true',
                'expected' => 'false in production',
                'target' => 'projectConfig.watching',
                'impact' => t('Craft compares timestamps and, when they differ, reloads and diffs the whole config. In production nothing should be editing those files outside a deploy, so the work never finds anything.', category: 'microscope'),
                'remediation' => "```php\n// config/craft/general.php\nreturn GeneralConfig::create()\n    ->allowAdminChanges(false);\n```\n\nApply config changes explicitly on deploy instead:\n\n```bash\nphp artisan craft:up\n```",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Project config is a sensible size', category: 'microscope'),
                $size !== null ? Bytes::format($size) : null,
            );
        }

        return $findings;
    }

    private function sizeInstructions(): string
    {
        return <<<'MD'
Find what's taking the room:

```bash
du -sh config/craft/project/* | sort -rh | head
```

The usual answers:

- **Uninstalled plugins that left their config behind.** A plugin that doesn't clean up on
  uninstall leaves its top-level key in the config forever, where it shows up in every
  `craft:project-config:diff` and gets carried to every environment. Check for keys belonging to
  plugins you no longer have:
  ```bash
  php artisan craft:project-config:diff
  ```
  and remove orphans deliberately with `php artisan craft:project-config:remove <path>`.
- **Field layouts on a site with many entry types.** These are inherently large; there's nothing
  to fix beyond consolidating entry types that barely differ.
- **A large number of sites**, each multiplying section and field layout config.

**Speed up the apply step regardless of size:**

```php
// config/craft/general.php
return GeneralConfig::create()
    ->allowAdminChanges(false); // production only
```

and make sure deploys run:

```bash
php artisan craft:up
```

rather than letting the first web request after a deploy discover the change and apply it inline —
which is both slow and racy if several requests arrive at once.
MD;
    }
}
