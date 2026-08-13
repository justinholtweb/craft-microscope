# Microscope for Craft CMS

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

**Craft** — `devMode` in production, template caching, the cache and session drivers, how the
queue is run, image driver and transform timing, revision and soft-delete garbage collection, and
project config size.

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

Craft has no scheduler, so pick how scans get started under **Microscope → Settings**:

**Cron** (recommended). Run this hourly; it checks whether a scan is due and starts one only when
it is:

```bash
0 * * * * cd /path/to/site && php craft microscope/scan/scheduled
```

**Control panel requests**, for hosting without usable cron. Microscope checks after the response
has been sent, so nobody waits for it, and pushes the scan onto the queue behind a lock so
concurrent requests can't start several.

Either way you can have the result emailed. The default is to send only when something **new**
appears — a report that arrives unchanged every week stops being read.

## Console

```bash
php craft microscope/scan/run                       # scan and print the result
php craft microscope/scan/run --verbose             # include how to fix each finding
php craft microscope/scan/run --category=templates  # one area only
php craft microscope/scan/run --fail-on=critical    # non-zero exit for CI
php craft microscope/scan/scheduled                 # run only if the schedule says so
php craft microscope/scan/report --pdf=report.pdf   # export a stored scan
php craft microscope/scan/list                      # recent scans
php craft microscope/scan/checks                    # the catalogue
php craft microscope/scan/prune 20                  # keep the last 20
```

`--fail-on` is what makes this useful in a deploy pipeline: a build can refuse to ship a change
that turns OPcache off or leaves `devMode` on.

**One caveat about CLI scans.** They read the *CLI* PHP configuration, which routinely differs from
the one serving the site — different `memory_limit`, different `max_execution_time`, and very often
no OPcache. Microscope records which SAPI each scan came from and the PHP findings say so, but for
the PHP area specifically a scan run from the control panel describes the site more accurately.

## Templates

```twig
{{ craft.microscope.score() }}            {# 0–100, or null if never scanned #}
{{ craft.microscope.grade() }}            {# A–F #}
{% for finding in craft.microscope.findings('php') %}
  {{ finding.title }} — {{ finding.severity }}
{% endfor %}
{{ craft.microscope.nextScheduledScan()|datetime }}
```

## Permissions

- **View scans and reports** — the dashboard, scans, the catalogue and reports.
- **Run scans** — start one.
- **Manage checks and the schedule** — enable and disable checks, delete scans, email reports.

Settings are admin-only.

## Extending it

Register your own checks — a plugin knows better than Microscope does what "misconfigured" looks
like for it, and its findings belong in the same report:

```php
use justinholtweb\microscope\events\RegisterChecksEvent;
use justinholtweb\microscope\services\Checks;
use yii\base\Event;

Event::on(Checks::class, Checks::EVENT_REGISTER_CHECKS, function(RegisterChecksEvent $event) {
    $event->checks[] = new MyCheck();
});
```

A check implements `CheckInterface`: describe itself, say whether it can run in this environment,
and return findings. `BaseCheck` provides `critical()`, `warning()`, `notice()` and `pass()`.

`Scans::EVENT_BEFORE_SCAN` is cancellable, which is the hook for suppressing scheduled scans
during a deploy window.

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- MySQL or MariaDB for the database checks (the category skips itself on PostgreSQL)

## Installation

```bash
composer require justinholtweb/craft-microscope
php craft plugin/install microscope
```

## Development

The repo ships a DDEV project so the test suite runs against a pinned PHP and a real database:

```bash
ddev start
ddev composer install
ddev test                     # unit + integration
ddev test --testsuite Unit    # no database needed
ddev analyse                  # PHPStan + code style
```

See [`tests/README.md`](tests/README.md) for what's covered where, and why.
