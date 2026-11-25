# Release Notes for Microscope

## 6.0.0-beta.1

Microscope for Craft CMS 6, rebuilt as a native Craft 6 plugin. It runs on Laravel and has no
dependency on the Yii 2 adapter. Craft 6 is in beta, and so is this release. Update to Microscope
5.0.3 or later before upgrading Craft, and see
[the upgrade guide](https://github.com/justinholtweb/craft-microscope/blob/6.0.0/UPGRADING.md).

### Added

- MCP tools on Craft's MCP server: `microscope_status`, `microscope_scans_list`,
  `microscope_scan_get`, `microscope_checks_list` and `microscope_scan_run`, acting as the MCP user
  with Microscope's permissions. Same names and output as Microscope 5.1.
- GraphQL: `microscopeScans`, `microscopeScan` and `microscopeChecks`, behind a *Microscope* schema
  component. Read-only, and never on the public schema. Same schema as Microscope 5.1.
- **Settings → Control panel**: a custom sidebar label, an icon from Craft's icon picker (custom icon
  sets included), or a pasted SVG, sanitised every time it's drawn.
- Laravel checks in the Craft configuration area: whether config, routes and events are cached in
  production (`laravelOptimization`), the session driver (`sessionDriver`) and the log level and
  channel (`logLevel`). The debug-mode, cache and queue checks now read Laravel's `APP_DEBUG`,
  cache store and queue connection.
- Scheduled scans run on Laravel's scheduler: `microscope:scan:scheduled` is registered hourly, so a
  site running `php artisan schedule:run` needs no cron line of Microscope's own.
- Scheduled-scan notices can also go to a Slack webhook and to the control panel notifications of
  everyone who can manage Microscope.
- A scan webhook: a signed JSON POST (`X-Microscope-Signature`), from the queue, after every
  completed scan, with the score, the problems and what's new or resolved.
- Laravel events: `ScanStarting` (cancellable, and can change the scan's config), `ScanCompleted`
  and `ScanFailed`; `RegisteringChecks` to add or remove checks.
- `microscope:scan:run --json` prints the scan in the `microscope_scan_get` shape, for CI, and
  `--queue` queues it instead.
- `microscope.score()` and the rest of the template API in Twig and Blade (`$microscope->score()`).
  `craft.microscope` still works.
- A `microscope` log channel (`storage/logs/microscope-*.log`), which a site can redefine in
  `config/logging.php` to send Microscope's messages anywhere Laravel can.

### Changed

- Console commands are Artisan commands: `php artisan microscope:scan:run`, `:scheduled`, `:list`,
  `:report`, `:prune` and `microscope:checks`. The Craft 5 names (`microscope/scan/run` …) still
  work. `-v` shows remediation, as `--verbose` did.
- Settings use Craft 6's standard plugin settings screen. Every key is unchanged.
- PHP 8.5 and Craft 6.0.0-beta.1 or later.

## 5.0.3

### Changed

- The control panel templates no longer use inline styles. Their layout and spacing now come from
  Microscope's stylesheet, built on Craft's CP custom properties, so the dashboard, scan, checks
  and settings pages and the dashboard widget follow the control panel's theme consistently. The
  cron command on the Settings page now gets the same code-block background as findings. ([#3])

[#3]: https://github.com/justinholtweb/craft-microscope/issues/3

## 5.0.2

### Fixed

- `relationInLoop` no longer flags a relation that has already been lazily eager-loaded. Craft 5's
  `entry.topics.eagerly().all()` runs one query for the whole loop, but the rule matched any
  `.all()`, `.one()`, `.count()`, `.exists()` or `.nth()` inside a loop and so kept reporting it —
  which meant following the rule's own remediation could never clear the warning. The check now
  reads the method chain the call belongs to, so `.eagerly()` anywhere in it (including
  `.eagerly('alias')` and `.eagerly().limit(3).all()`) is skipped, while a bare relation elsewhere
  on the same line is still reported. ([#2])

[#2]: https://github.com/justinholtweb/craft-microscope/issues/2

## 5.0.1

### Fixed

- CLI scans no longer report the console binary's PHP configuration as the site's. `memory_limit`,
  `upload_max_filesize`, `post_max_size`, `max_input_vars`, `max_execution_time` and the realpath
  cache are read from whichever `php.ini` the running SAPI loaded, which on Herd, Valet, DDEV,
  Docker and most shared hosting is not the one PHP-FPM uses — so a healthy site could be flagged
  with critical and warning findings it didn't have. The memory limit, request limits and realpath
  cache checks are now skipped outside a web SAPI, with the reason recorded and no effect on the
  score, and OPcache's sizing analysis is skipped the same way. ([#1])

[#1]: https://github.com/justinholtweb/craft-microscope/issues/1

## 5.0.0

- Initial release.
- Performance scans across six areas: PHP, database, server, Craft configuration, content schema and Twig templates.
- Every finding carries a plain-English explanation of the cost and a step-by-step fix.
- Scheduled scans, daily/weekly/monthly, with email notification on new or worsening findings.
- Reports as a print-optimised page and as a downloadable PDF.
- Control panel dashboard with an overall score, per-area breakdown and score history.
- Dashboard widget, Twig variable and console commands (`microscope/scan/run`, `scheduled`, `report`, `prune`).
