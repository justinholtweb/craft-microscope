# Upgrading Microscope from Craft 5 to Craft 6

Microscope 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. Your scan history and settings carry over: Microscope's tables are unchanged.

> **Beta.** Microscope 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> first. Microscope 5 remains the stable release for Craft 5.

## Before you start

| | Microscope 5.x | Microscope 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.3+ (5.12.1+ for MCP) | 6.0.0-beta.1+ |
| PHP | 8.2+ | 8.5+ |
| Install | `php craft plugin/install microscope` | `php artisan craft:plugin:install microscope` |
| Scan from the CLI | `php craft microscope/scan/run` | `php artisan microscope:scan:run` (the old name still works via `php craft`) |
| Scheduled scans | cron: `php craft microscope/scan/scheduled` | Laravel's scheduler (`php artisan schedule:run`) |

1. **Update to Microscope 5.0.3 or later first**, while the site is still on Craft 5
   (5.1.0 adds the MCP, GraphQL and sidebar settings that Microscope 6 also has):

   ```bash
   composer require justinholtweb/craft-microscope:^5.1
   php craft up
   ```

2. **Let any queued scan finish.** A Microscope 5 scan job left on the Craft 5 queue doesn't move
   to Laravel's queue.

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), and require
Microscope 6 at the same time as Craft:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-microscope:^6.0@beta" -W
php artisan craft:up
```

## What carries over

- **Scans and findings.** `microscope_scans` and `microscope_findings` keep their rows, so the score
  history and "new since the last scan" continue across the upgrade.
- **Settings.** Every key is the same in project config, including `navLabel`, `navIcon` and
  `navIconSvg` from 5.1.
- **Config file.** Move `config/microscope.php` to `config/craft/microscope.php`. Same keys.
- **Permissions.** Same names (`microscope:view`, `microscope:scan`, `microscope:manage`).
- **Check handles**, so disabled checks stay disabled.
- **MCP tools and GraphQL queries**: identical names and output in 5.1 and 6.
- **Templates**: `craft.microscope.*` still works; `microscope.*` (Twig) and `$microscope->…`
  (Blade) are new.

## What changed

- **Scheduled scans** run on Laravel's scheduler. Replace your Microscope cron line with Laravel's
  (`* * * * * php artisan schedule:run`) if the site doesn't have it already; the old command still
  works if you'd rather keep it. "Control panel requests" works as before.
- **Craft configuration checks** read Laravel's settings: `APP_DEBUG` instead of `devMode`, the
  cache store and queue connection from `config/cache.php` and `config/queue.php`. Three checks are
  new — `laravelOptimization`, `sessionDriver` and `logLevel` — so expect the Craft configuration
  score to move on the first scan after upgrading. `generateTransformsBeforePageLoad` no longer
  exists, so its finding is gone.
- **Console flags.** `--verbose` is Laravel's `-v`, and still shows remediation.
- **Extending.** `Checks::EVENT_REGISTER_CHECKS` and `Scans::EVENT_BEFORE_SCAN`/`EVENT_AFTER_SCAN`
  are now Laravel events: `RegisteringChecks`, `ScanStarting` and `ScanCompleted` (plus
  `ScanFailed`), in `JustinHoltWeb\Microscope\Events`. Checks implement
  `JustinHoltWeb\Microscope\Checks\CheckInterface`; findings are built the same way.
- **Logging** goes to a `microscope` Laravel log channel (`storage/logs/microscope-*.log`), which
  `config/logging.php` can redefine.
- **Templates.** Site templates are read from `resources/views` when that folder exists, otherwise
  `templates/`. Blade templates are counted but not analysed yet.

## New in 6

Slack and control panel notifications after scheduled scans, a signed scan webhook,
`microscope:scan:run --json` and `--queue`, and Blade support. See the
[changelog](https://github.com/justinholtweb/craft-microscope/blob/6.0.0/CHANGELOG.md).
