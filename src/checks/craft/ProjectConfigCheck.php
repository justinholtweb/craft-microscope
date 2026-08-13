<?php

namespace justinholtweb\microscope\checks\craft;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

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
        return Craft::t('microscope', 'Project config');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks the size of the project config and whether Craft is watching it for changes in production.');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->craft->usesProjectConfigFile();
    }

    public function unavailableReason(CheckContext $context): string
    {
        return Craft::t('microscope', 'This site doesn’t keep its project config in files, so there is nothing to read or diff.');
    }

    public function run(CheckContext $context): array
    {
        $size = $context->craft->projectConfigSize();
        $findings = [];

        if ($size !== null && $size > self::LARGE) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'The project config is large'),
                'summary' => Craft::t('microscope', 'The YAML in `config/project/` totals {size}.', ['size' => Bytes::format($size)]),
                'observed' => Bytes::format($size),
                'expected' => Craft::t('microscope', 'Under {size}', ['size' => Bytes::format(self::LARGE)]),
                'target' => 'projectConfig.size',
                'impact' => Craft::t('microscope', 'Craft parses the config and compares it against the stored copy whenever it needs to know whether the two have diverged. On a large config that is real work, and `project-config/apply` on deploy gets slower in proportion.'),
                'remediation' => $this->sizeInstructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/system/project-config.html',
            ]);
        }

        if ($context->isProduction() && $context->craft->allowAdminChanges()) {
            // Reported by DevModeCheck as a config problem; here it's specifically about the cost.
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Craft is watching the project config for external changes'),
                'summary' => Craft::t('microscope', 'With `allowAdminChanges` on, Craft checks whether the YAML files have changed on disk.'),
                'observed' => 'allowAdminChanges = true',
                'expected' => 'false in production',
                'target' => 'projectConfig.watching',
                'impact' => Craft::t('microscope', 'Craft compares timestamps and, when they differ, reloads and diffs the whole config. In production nothing should be editing those files outside a deploy, so the work never finds anything.'),
                'remediation' => "```php\n// config/general.php\n'allowAdminChanges' => false,\n```\n\nApply config changes explicitly on deploy instead:\n\n```bash\nphp craft up\n```",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Project config is a sensible size'),
                Bytes::format($size),
            );
        }

        return $findings;
    }

    private function sizeInstructions(): string
    {
        return <<<'MD'
Find what's taking the room:

```bash
du -sh config/project/* | sort -rh | head
```

The usual answers:

- **Uninstalled plugins that left their config behind.** A plugin that doesn't clean up on
  uninstall leaves its top-level key in the config forever, where it shows up in every
  `project-config/diff` and gets carried to every environment. Check for keys belonging to
  plugins you no longer have:
  ```bash
  php craft project-config/diff
  ```
  and remove orphans deliberately with `php craft project-config/remove <path>`.
- **Field layouts on a site with many entry types.** These are inherently large; there's nothing
  to fix beyond consolidating entry types that barely differ.
- **A large number of sites**, each multiplying section and field layout config.

**Speed up the apply step regardless of size:**

```php
// config/general.php
'allowAdminChanges' => false,   // production only
```

and make sure deploys run:

```bash
php craft up
```

rather than letting the first web request after a deploy discover the change and apply it inline —
which is both slow and racy if several requests arrive at once.
MD;
    }
}
