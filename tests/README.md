# Tests

Pest 5 on `CraftCms\Cms\Plugin\Testing\PluginTestCase`: every test boots a real Craft 6 install with
Microscope installed, on in-memory SQLite (see `phpunit.xml`), inside a rolled-back transaction.

- `Unit/` — pure logic: scoring, cadence, byte and ini parsing, version comparison, the template
  analyzer, the Laravel-aware checks against fabricated probes, settings validation and the nav.
- `Feature/` — scans end to end with `Support/FakeCheck` standing in for real checks (so the outcome
  is the test's), the MCP tools as different users, GraphQL with and without the scope, the control
  panel routes and permissions, the webhook, notifications, scheduling and the console commands.
  `DatabaseProbeTest` runs against MySQL when `MICROSCOPE_DB_*` env vars are set, and skips otherwise.

```bash
vendor/bin/pest
```
