# Microscope for Craft CMS 6

> **Beta.** This is Microscope 6, a native Craft 6 plugin (Laravel, no Yii 2 adapter), in beta like Craft 6
> itself. Microscope 5 remains the stable release for Craft 5. Upgrading? See [UPGRADING.md](UPGRADING.md).

Put your Craft site under the microscope. Microscope audits your PHP runtime, database, server,
Craft configuration, content schema and Twig templates for the things that make a site slow — and
for each one, tells you exactly what it costs and how to fix it.

It is not a profiler and it does not sit in the request path. It runs on demand or on a schedule,
writes a report, and gets out of the way.

## Why another audit tool

Most performance advice for Craft is generic: "enable OPcache", "use eager loading". Microscope
tells you whether *this* server has OPcache on, how big it is, whether it's full, and — if it
isn't right — the exact `php.ini` lines to change and how to confirm they took effect. Every
finding carries:

- **what was measured** and what it should be,
- **why it costs something**, in plain language,
- **the steps to fix it**, with config snippets, and
- **how to verify** the fix worked.

That's what makes a report worth handing to a client, or to a host, or to the developer who
inherits the site next year.

## What it checks

**PHP** — version, OPcache (enabled, sized, hit rate, restarts, timestamp validation), memory
limit, execution time, `max_input_vars`, upload limits, realpath cache, and the optional
extensions that unlock faster paths (APCu, Redis, Imagick, intl).

**Database** — MySQL/MariaDB version, InnoDB buffer pool against the actual data size, temporary
tables spilling to disk, connection and thread caches, the slow query log, MariaDB's query cache,
storage engines, and which of Craft's own tables have grown out of proportion.

**Server** — CPU, available memory and load per core, disk space, gzip and Brotli compression,
HTTP/2 and TLS.

**Craft and Laravel** — debug mode (`APP_DEBUG`) and admin changes in production, whether config,
routes and events are cached (`php artisan optimize`), the cache store, session driver, queue
connection, backlog and failures, the log level and channel, template caching, image driver,
garbage collection and project config size.

**Content schema** — unused fields, field layouts wide enough to slow the entry editor (or to
overflow `max_input_vars` and silently lose content on save), and overall content volume.

**Templates** — static analysis of every `.twig` file for element queries inside loops, relational
fields executed per row, unbounded `.all()`, `.all()|length` where `.count()` belongs, transforms
generated in loops, query-heavy templates with no `{% cache %}`, includes without `only`, and
images missing dimensions or loading hints.

27 checks in total, plus 8 template rules. Every one can be switched off from **Microscope →
Checks** when it doesn't apply to how your site is hosted.

## Scores

Each area is scored out of 100 by subtracting the cost of what's wrong: a critical finding costs
25, a warning 10, a notice 3. The overall score is the mean of the areas that actually ran.

Checks that *couldn't* run — a Postgres site, a host that won't allow `shell_exec`, a scan taken
from the CLI where there's no HTTP request to inspect — are recorded as skipped and excluded from
the score. Microscope reports what it can see and says plainly what it can't; it never guesses.

## Reports

Two outputs from the same document:

- **A print-optimised page** at `microscope/scans/<id>/report`, which the browser saves as a PDF
  with better typography than any library produces.
- **A real PDF file** at `microscope/scans/<id>/report.pdf`, rendered server-side, suitable for
  emailing, attaching to a scheduled scan, or archiving.

Server-side PDF needs [dompdf](https://github.com/dompdf/dompdf), which is an **optional**
dependency:

```bash
composer require dompdf/dompdf
```

It is optional deliberately. Every published dompdf release carries a security advisory, and
Composer's audit policy blocks flagged packages by default — requiring it would make Microscope
uninstallable on sites with that policy on. Without dompdf, everything still works; the PDF route
redirects to the printable one. Both dompdf 2.x and 3.x are supported, so a site that already has
it via Craft Commerce, Formie or Freeform needs no extra install.

## Scheduled scans

Turn scheduling on under **Settings → Plugins → Microscope**, then pick how due scans get started:

**Laravel's scheduler** (recommended). Microscope registers `microscope:scan:scheduled` hourly; the
command starts a scan only when one is due. A site already running the scheduler needs nothing else:

```bash
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

**Control panel requests**, for hosting without a scheduler. Microscope checks after the response
has been sent, at most once a minute, and queues the scan behind a cache lock so concurrent
requests can't start several.

After a scheduled scan Microscope can email the report (with the PDF), post to a Slack incoming
webhook, and notify everyone who can manage Microscope in the control panel. The default is to
notify only when something **new** appears — a report that arrives unchanged every week stops
being read.

### Webhook

Set **Scan webhook URL** and Microscope POSTs a JSON summary after every completed scan — scheduled,
manual or console — from the queue, retried with backoff:

```json
{"event": "scan.completed", "site": {"name": "…", "url": "…"},
 "scan": {"id": 42, "score": 87, "grade": "B"}, "previousScore": 91,
 "problems": [{"checkHandle": "opcache", "category": "php", "severity": "critical", "title": "…", "target": null, "fingerprint": "opcache:"}],
 "new": [], "resolved": [], "sentAt": "2026-11-24T10:00:00+00:00"}
```

With a **Webhook secret**, each body is signed: `X-Microscope-Signature: sha256=<hex HMAC-SHA256 of
the raw body>`.

## Console

```bash
php artisan microscope:scan:run                       # scan and print the result
php artisan microscope:scan:run -v                    # include how to fix each finding
php artisan microscope:scan:run --category=templates  # one area only
php artisan microscope:scan:run --fail-on=critical    # non-zero exit for CI
php artisan microscope:scan:run --json                # the microscope_scan_get shape, for scripts
php artisan microscope:scan:run --queue               # queue it instead
php artisan microscope:scan:scheduled                 # run only if the schedule says so
php artisan microscope:scan:report --pdf=report.pdf   # export a stored scan
php artisan microscope:scan:list                      # recent scans
php artisan microscope:checks                         # the catalogue
php artisan microscope:scan:prune 20                  # keep the last 20
```

The Craft 5 names still work through Craft's own binary: `php craft microscope/scan/run`.

`--fail-on` is what makes this useful in a deploy pipeline: a build can refuse to ship a change
that turns OPcache off or leaves `devMode` on.

**One caveat about CLI scans.** `ini_get()` reports the configuration of the process that calls it,
and the console binary loads its own `php.ini` — on Herd, Valet, DDEV, Docker and most shared
hosting, a different one from PHP-FPM's. A site set to `memory_limit = 1280M` for web requests
would be measured at the console's 128 MB default.

So rather than report a number from the wrong process, Microscope skips the checks that depend on
one: **memory limit**, **request limits**, **realpath cache**, and OPcache's sizing analysis. They
appear under "Checks that couldn't run" with the SAPI named, and they don't count against the
score. Everything else — PHP version, extensions, the database, Craft's configuration, your
content schema and your templates — is the same from either side, so a CLI scan still covers most
of the ground. For the PHP area, run the scan from the control panel.

The same applies to scheduled scans run by a console queue worker (`php artisan queue:work`). If
you want the PHP area covered on a schedule, leave `runQueueAutomatically` on so the queue runs
from web requests, or trigger the scan from the control panel.

## Templates

The same read-only API in Twig and Blade (`craft.microscope` still works too):

```twig
{{ microscope.score() }}            {# 0–100, or null if never scanned #}
{{ microscope.grade() }}            {# A–F #}
{% for finding in microscope.findings('php') %}
  {{ finding.title }} — {{ finding.severity }}
{% endfor %}
{{ microscope.nextScheduledScan()|datetime }}
```

```blade
{{ $microscope->score() }} ({{ $microscope->grade() }})
@foreach ($microscope->findings('php') as $finding)
  {{ $finding->title }}
@endforeach
```

## Permissions

- **View scans and reports** — the dashboard, scans, the catalogue and reports.
- **Run scans** — start one.
- **Manage checks and scans** — enable and disable checks, delete scans, email reports.

Settings are admin-only.

## AI agents (MCP)

Microscope adds five tools to Craft's own MCP server, so an agent connected to the site
(`php artisan craft:mcp:serve --user=<you>` over stdio, Craft 6's built-in assistant, or Craft's
HTTP MCP endpoint) can read the site's performance and act on it.

| Tool | What it does | Needs |
|---|---|---|
| `microscope_status` | Current score and grade, the score per area, problems new and resolved since the previous scan, and the schedule | View scans and reports |
| `microscope_scans_list` | Scans, newest first (`limit` 1–100, `offset`) | View scans and reports |
| `microscope_scan_get` | One scan's findings, most severe first, each with what was measured, what it should be, the impact and the fix. Omit `scanId` for the latest. Filters: `category`, `severity`, `includePasses`, `onlyNew`; `includeEvidence` adds up to 25 supporting rows per finding | View scans and reports |
| `microscope_checks_list` | The check catalogue, with whether each check is on (`category` filter) | View scans and reports |
| `microscope_scan_run` | Puts a scan on the queue, of everything or of the `categories` given. It runs when the queue does, never inside the tool call | Run scans |

All of them also need control panel access, and none is offered on Craft's public MCP server. The
read tools are read-only (`mcp:read` scope); `microscope_scan_run` needs `mcp:write`.

Output uses the same conventions as the rest of Microscope: dates are ISO-8601, `score` is
0–100, `grade` A–F, and `remediation` is Markdown. Tool names and output are the same in
Microscope 5 and 6.

A scan queued from an agent and run by a console queue worker (`php artisan queue:work`) skips the
memory limit, request limits and realpath cache checks, because the CLI loads its own `php.ini` —
the same as a scan from `php artisan microscope:scan:run`.

## GraphQL

Microscope adds read-only queries to Craft's GraphQL API. Enable **Microscope → Query Microscope
scans and findings** on a schema to use them. They are never available on the public schema, even
with that box ticked: findings describe the server's configuration.

```graphql
{
  microscopeScan {          # omit `id` for the latest completed scan
    id score grade dateCreated
    categoryScores { category label score }
    skipped { checkHandle reason }
    findings(severity: "critical") {   # also category: "php" | …, includePasses: true
      checkHandle category severity title summary
      observed expected impact remediation docsUrl
      target occurrences fingerprint
    }
  }
  microscopeScans(limit: 20, offset: 0) { id status trigger score grade criticalCount warningCount noticeCount passCount runtime error dateStarted dateFinished }
  microscopeChecks(category: "database") { handle category label description enabled }
}
```

Dates are ISO-8601 strings. A finding's supporting evidence and the scan's environment summary
aren't exposed over GraphQL.

## Sidebar label and icon

**Settings → Plugins → Microscope → Control panel** changes how Microscope appears in the control panel's sidebar:

- **Sidebar label** — replaces "Microscope".
- **Sidebar icon** — any icon from Craft's icon picker, including custom icon sets.
- **Custom SVG icon** — paste one `<svg>` element to use instead. It is sanitised every time it's
  drawn (scripts and event handlers are stripped); a single-colour icon using `currentColor`
  matches the rest of the sidebar.

The SVG wins over the icon, and an override in Craft's own **Settings → Control Panel Navigation**
wins over both. The keys are `navLabel`, `navIcon` and `navIconSvg`, so they can also be set in
`config/craft/microscope.php`. The badge counting critical findings stays either way.

## Extending it

Register your own checks — a plugin knows better than Microscope does what "misconfigured" looks
like for it, and its findings belong in the same report:

```php
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Microscope\Events\RegisteringChecks;

Event::listen(RegisteringChecks::class, function (RegisteringChecks $event) {
    $event->checks[] = new MyCheck();
});
```

A check implements `JustinHoltWeb\Microscope\Checks\CheckInterface`: describe itself, say whether it
can run in this environment, and return findings. `BaseCheck` provides `critical()`, `warning()`,
`notice()` and `pass()`.

Other events: `ScanStarting` (set `$isValid = false` to cancel — say, during a deploy window — or
change `$config`), `ScanCompleted` (the scan, its result and the previous scan) and `ScanFailed`.
Microscope logs to a `microscope` channel; define one in `config/logging.php` to send it elsewhere.

## Requirements

- Craft CMS 6.0.0-beta.1+
- PHP 8.5+
- MySQL or MariaDB for the database checks (the area skips itself on PostgreSQL and SQLite)

## Installation

```bash
composer require "justinholtweb/craft-microscope:^6.0@beta"
php artisan craft:plugin:install microscope
```

## Development

The suite is Pest on Craft's `PluginTestCase` — a real Craft 6 install with Microscope, on in-memory
SQLite:

```bash
composer install
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

See [`tests/README.md`](tests/README.md).
