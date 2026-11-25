<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Database;

use JustinHoltWeb\Microscope\Checks\CheckContext;

use function CraftCms\Cms\t;

/**
 * Whether slow queries are being recorded, and whether any have been.
 *
 * The only check in the database category whose remediation is "turn on the instrument", and it
 * earns its place: without the slow query log, every conversation about a slow Craft site is
 * guesswork.
 */
class SlowQueryLogCheck extends BaseDatabaseCheck
{
    public function handle(): string
    {
        return 'slowQueryLog';
    }

    public function label(): string
    {
        return t('Slow query log', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks whether slow queries are being logged, and reports how many the server has seen.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $enabled = $db->boolVariable('slow_query_log');
        $threshold = $db->variable('long_query_time');
        $slowQueries = $db->statusValue('slow_queries');
        $findings = [];

        if ($enabled === false) {
            $findings[] = $this->notice([
                'title' => t('Slow query log is off', category: 'microscope'),
                'summary' => t('The database isn’t recording which queries take longest, so there’s no evidence to work from when the site is slow.', category: 'microscope'),
                'observed' => t('Disabled', category: 'microscope'),
                'expected' => t('Enabled, threshold 1s', category: 'microscope'),
                'target' => 'slow_query_log',
                'impact' => t('This costs nothing directly — it’s the absence of evidence that costs. Without it, diagnosing a slow page means reproducing it while watching `SHOW PROCESSLIST`, which only works if you can reproduce it on demand.', category: 'microscope'),
                'remediation' => $this->enableInstructions(),
                'docsUrl' => 'https://dev.mysql.com/doc/refman/8.0/en/slow-query-log.html',
            ]);
        }

        if ($slowQueries !== null && $slowQueries > 0 && $this->hasMeaningfulStatistics($context)) {
            $uptime = max($db->uptime() ?? 1, 1);
            $perHour = $slowQueries / ($uptime / 3600);

            if ($perHour >= 1) {
                $findings[] = $this->warning([
                    'title' => t('The database is running slow queries', category: 'microscope'),
                    'summary' => t('{count} queries have exceeded the {threshold}s threshold — roughly {rate} an hour.', [
                        'count' => number_format($slowQueries),
                        'threshold' => $threshold ?? '10',
                        'rate' => number_format($perHour, 1),
                    ], category: 'microscope'),
                    'observed' => number_format($slowQueries).t(' slow queries', category: 'microscope'),
                    'expected' => t('Near zero', category: 'microscope'),
                    'target' => 'slow_queries',
                    'impact' => t('Each one is a request somewhere on the site waiting on the database for longer than the threshold. They also hold connections and buffers for the duration, so a handful of slow queries can make everything else slower.', category: 'microscope'),
                    'remediation' => $this->analysisInstructions($threshold),
                ]);
            }
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Slow query logging is on and quiet', category: 'microscope'),
                t('{count} slow queries, threshold {threshold}s', [
                    'count' => number_format($slowQueries ?? 0),
                    'threshold' => $threshold ?? '?',
                ], category: 'microscope'),
            );
        }

        return $findings;
    }

    private function enableInstructions(): string
    {
        return <<<'MD'
```ini
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 1
log_queries_not_using_indexes = 0
```

Or without a restart:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;
```

Two notes on the settings above:

- **`long_query_time = 1`**, not the default 10. A query taking ten seconds is an outage; the ones
  worth finding are the 1–2 second ones happening on every page load.
- **Leave `log_queries_not_using_indexes` off** unless you're actively hunting. Craft runs plenty
  of legitimate small unindexed queries against tiny tables, and turning it on fills the log with
  them.

On managed hosting the slow query log is usually a toggle in the provider's console, and the log
is exposed through their interface rather than as a file.
MD;
    }

    private function analysisInstructions(?string $threshold): string
    {
        $current = $threshold ?? '10';

        return <<<MD
Find out which queries they are — the threshold is currently **{$current}s**.

**Summarise the log.** `mysqldumpslow` ships with MySQL and needs nothing installed:

```bash
mysqldumpslow -s t -t 20 /var/log/mysql/slow.log
```

`-s t` sorts by total time spent, which surfaces the query that costs the most overall rather than
the single slowest instance — usually a different query, and usually the more important one.
`pt-query-digest` from Percona Toolkit gives a much better report if you can install it.

**Then look at what the query is.** In a Craft site the recurring offenders are:

- **`searchindex` queries.** Craft's search does `LIKE '%term%'`, which cannot use an index. If
  search is slow and important, move it to a dedicated engine.
- **Ordering by a custom field.** The sort happens on a joined table, so no single index covers
  the filter and the order together.
- **`relations` joins on entries with many relations.** Check `EXPLAIN` for a join without a key.
- **Queries returning far more rows than the page shows** — an element query with no `.limit()`.
  Microscope's template category finds these.

**Get the plan for a specific query:**

```sql
EXPLAIN ANALYZE SELECT …;
```

`type: ALL` means a full table scan; `Using filesort` and `Using temporary` together on a large
table are what turn a fast query into a slow one.

For the Craft side of this, turn on `devMode` locally and open the **Debug Toolbar's** Database
panel — it lists every query a page ran, with timings and the stack trace that issued it, which
is far quicker than working backwards from the log.
MD;
    }
}
