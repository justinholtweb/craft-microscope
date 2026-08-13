# Testing Microscope

```bash
ddev start
ddev composer install
ddev test                       # everything
ddev test --testsuite Unit      # no database needed
ddev test --filter TemplateAnalyzerTest
```

Without DDEV, `composer install && vendor/bin/phpunit` works the same way — the Unit suite needs
nothing but PHP.

## What's covered where

**`tests/Unit`** — plain PHP, no Craft application, no database.

This is where the parts of the plugin that were written to be pure get exercised: the Twig
template analyzer (it takes a string and returns issues, so a test can hand it a template that
doesn't exist on disk), the ini and byte parsing that every PHP check depends on, version
comparison against the odd banners real database servers report, the scoring arithmetic, and the
schedule's date maths.

These are the parts where being wrong is *silently* wrong. A `memory_limit` parser that reads
`256M` as `256` would have Microscope reporting a 256-byte memory limit and nobody would notice
until a client asked about it.

**`tests/Integration`** — needs a live MySQL or MariaDB.

The database probe runs real `SHOW VARIABLES`, `SHOW GLOBAL STATUS` and `information_schema`
queries against the DDEV database service. The point is that server-tuning advice which has only
ever been tested against a fixture is advice nobody should take — these tests confirm the probe
reads a real server correctly, including the MariaDB version banner and the `information_schema`
row-estimate columns.

They skip themselves cleanly when no database is reachable, so `vendor/bin/phpunit` outside DDEV
still passes.

Connection details come from the environment (DDEV sets these in `.ddev/config.yaml`):

```
MICROSCOPE_DB_HOST      default: 127.0.0.1
MICROSCOPE_DB_PORT      default: 3306
MICROSCOPE_DB_NAME      default: db
MICROSCOPE_DB_USER      default: db
MICROSCOPE_DB_PASSWORD  default: db
```

## What isn't covered here

Anything that needs a booted Craft — the checks that read `GeneralConfig`, the CP controllers, the
queue job, PDF rendering — is exercised against the shared plugin-testing harness rather than
mocked. Mocking Craft's field layout service to test a check that counts field layouts tests the
mock, not the check.

## Adding a check

A new check needs no test here unless it has logic worth isolating. If it does — a threshold
calculation, a parser, a ratio — put that logic in `src/helpers` or a small class of its own and
test it there, rather than testing the check by constructing a whole `CheckContext`. The checks
themselves are deliberately thin: read from a probe, compare against a constant, build a finding.
