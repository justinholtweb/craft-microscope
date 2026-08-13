<?php

namespace justinholtweb\microscope\checks\database;

use Craft;
use justinholtweb\microscope\checks\CheckContext;

/**
 * Connection handling: how many are allowed, how many have been refused, and whether the server is
 * paying to create them over and over.
 */
class ConnectionLimitsCheck extends BaseDatabaseCheck
{
    private const MIN_CONNECTIONS = 100;

    public function handle(): string
    {
        return 'connectionLimits';
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Connections');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks connection limits, refused connections and the thread and table caches.');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $findings = [];

        $maxConnections = $db->intVariable('max_connections');
        $maxUsed = $db->statusValue('max_used_connections');
        $aborted = $db->statusValue('aborted_connects');

        if ($maxConnections !== null && $maxUsed !== null && $maxConnections > 0) {
            $usage = $maxUsed / $maxConnections;

            if ($usage > 0.85) {
                $findings[] = $this->warning([
                    'title' => Craft::t('microscope', 'Connection limit has nearly been reached'),
                    'summary' => Craft::t('microscope', 'Peak usage was {used} of {max} allowed connections.', [
                        'used' => number_format($maxUsed),
                        'max' => number_format($maxConnections),
                    ]),
                    'observed' => number_format($maxUsed) . ' / ' . number_format($maxConnections),
                    'expected' => Craft::t('microscope', 'Peak below 70% of the limit'),
                    'target' => 'max_connections',
                    'impact' => Craft::t('microscope', 'When the limit is hit, further connections are refused outright — the site returns a database connection error rather than a slow page. Traffic spikes and queue workers competing with web requests are the usual triggers.'),
                    'remediation' => $this->connectionInstructions($maxConnections),
                ]);
            }
        }

        if ($maxConnections !== null && $maxConnections < self::MIN_CONNECTIONS) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Connection limit is low'),
                'summary' => Craft::t('microscope', 'The server allows {max} simultaneous connections.', ['max' => $maxConnections]),
                'observed' => (string)$maxConnections,
                'expected' => self::MIN_CONNECTIONS . '+',
                'target' => 'max_connections',
                'impact' => Craft::t('microscope', 'PHP-FPM workers, queue runners and the console all take their own connection. A limit below the FPM worker count means the site can refuse connections while the database is otherwise idle.'),
                'remediation' => $this->connectionInstructions($maxConnections),
            ]);
        }

        if ($aborted !== null && $aborted > 1000 && $this->hasMeaningfulStatistics($context)) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Connections are being aborted'),
                'summary' => Craft::t('microscope', '{count} connection attempts have failed since the server started.', [
                    'count' => number_format($aborted),
                ]),
                'observed' => number_format($aborted),
                'expected' => Craft::t('microscope', 'Near zero'),
                'target' => 'aborted_connects',
                'impact' => Craft::t('microscope', 'Each aborted connection is a request that failed or retried. Common causes are a `connect_timeout` shorter than the network round trip, credentials that are wrong on one host in a pool, or the connection limit above being reached during spikes.'),
                'remediation' => "Find out which it is before changing anything:\n\n```sql\nSHOW GLOBAL STATUS WHERE Variable_name IN\n  ('Aborted_connects', 'Aborted_clients', 'Connection_errors_max_connections',\n   'Connection_errors_internal', 'Threads_connected');\n```\n\n- `Connection_errors_max_connections` climbing → raise `max_connections`.\n- `Aborted_clients` climbing → clients disconnecting without closing cleanly; usually PHP being killed mid-query by a timeout.\n- Neither → check the error log (`SHOW VARIABLES LIKE 'log_error'`), which records the reason per failure.",
            ]);
        }

        // --- thread cache -------------------------------------------------
        if ($this->hasMeaningfulStatistics($context)) {
            $threadsCreated = $db->statusValue('threads_created');
            $connections = $db->statusValue('connections');

            if ($threadsCreated !== null && $connections !== null && $connections > 10000) {
                $ratio = $threadsCreated / max($connections, 1);

                if ($ratio > 0.05) {
                    $findings[] = $this->notice([
                        'title' => Craft::t('microscope', 'Thread cache is too small'),
                        'summary' => Craft::t('microscope', 'The server has created {created} threads for {connections} connections — it should be reusing them.', [
                            'created' => number_format($threadsCreated),
                            'connections' => number_format($connections),
                        ]),
                        'observed' => number_format($ratio * 100, 1) . '% of connections needed a new thread',
                        'expected' => Craft::t('microscope', 'Under 5%'),
                        'target' => 'thread_cache_size',
                        'impact' => Craft::t('microscope', 'Creating a thread is not free. With PHP opening a fresh connection for every request — which it does, unless persistent connections are on — a cold thread cache adds that cost to every page.'),
                        'remediation' => "```ini\n[mysqld]\nthread_cache_size = 32\n```\n\nOr without a restart:\n\n```sql\nSET GLOBAL thread_cache_size = 32;\n```\n\nA reasonable starting point is `8 + (max_connections / 100)`, rounded up. Watch `Threads_created` afterwards — it should stop climbing almost entirely.",
                    ]);
                }
            }

            // --- table open cache -----------------------------------------
            $openedTables = $db->statusValue('opened_tables');
            $tableOpenCache = $db->intVariable('table_open_cache');

            if ($openedTables !== null && $tableOpenCache !== null && $openedTables > $tableOpenCache * 10) {
                $findings[] = $this->notice([
                    'title' => Craft::t('microscope', 'Table open cache is too small'),
                    'summary' => Craft::t('microscope', 'Tables have been opened {opened} times against a cache of {cache}.', [
                        'opened' => number_format($openedTables),
                        'cache' => number_format($tableOpenCache),
                    ]),
                    'observed' => number_format($tableOpenCache) . ' slots, ' . number_format($openedTables) . ' opens',
                    'expected' => Craft::t('microscope', 'Enough slots for every table, several times over'),
                    'target' => 'table_open_cache',
                    'impact' => Craft::t('microscope', 'Craft joins many tables per query and each concurrent connection needs its own file descriptor for each one. Re-opening them repeatedly costs a filesystem operation inside the query.'),
                    'remediation' => "```ini\n[mysqld]\ntable_open_cache = 4000\ntable_definition_cache = 2000\n```\n\nThese consume file descriptors, so raise the server's limit alongside them:\n\n```ini\n[mysqld]\nopen_files_limit = 65535\n```\n\nOn systemd, that also needs `LimitNOFILE=65535` in the MySQL unit's override — the `my.cnf` value alone can't exceed what the process was given.",
                ]);
            }
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Connection handling is healthy'),
                Craft::t('microscope', 'Peak {used} of {max} connections', [
                    'used' => number_format($maxUsed ?? 0),
                    'max' => number_format($maxConnections ?? 0),
                ]),
            );
        }

        return $findings;
    }

    private function connectionInstructions(int $current): string
    {
        return <<<MD
The limit is currently **{$current}**. Size it from what actually connects, rather than picking a
round number:

```
max_connections >= (PHP-FPM pm.max_children)
                 + (queue workers)
                 + (console / cron sessions)
                 + 10 for headroom and monitoring
```

`pm.max_children` is in your PHP-FPM pool config (`/etc/php/8.3/fpm/pool.d/www.conf`) and is the
number that matters most — every worker can hold a connection simultaneously.

```ini
[mysqld]
max_connections = 200
```

```sql
-- takes effect immediately, lost on restart
SET GLOBAL max_connections = 200;
```

**Raising this is not free.** Each connection reserves per-connection buffers
(`sort_buffer_size`, `join_buffer_size`, `read_buffer_size`), so the theoretical worst-case memory
use is roughly `max_connections × sum-of-those-buffers`. If you raise the limit substantially,
check those buffers aren't set generously as well.

If connections are being exhausted by *idle* ones rather than busy ones, the fix is at the other
end — shorten how long the server holds them:

```ini
[mysqld]
wait_timeout = 300
interactive_timeout = 300
```
MD;
    }
}
