<?php

namespace justinholtweb\microscope\checks\database;

use Craft;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;

/**
 * MariaDB's query cache, which is almost always a net loss and is still on by default in places.
 *
 * MySQL removed it in 8.0 for good reason: every write to a table invalidates every cached query
 * touching that table, and the invalidation runs under a single global mutex. On a workload with
 * any write traffic at all — which is any Craft site with an editor logged in — the cache spends
 * more time coordinating than it saves.
 */
class QueryCacheCheck extends BaseDatabaseCheck
{
    public function handle(): string
    {
        return 'queryCache';
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Query cache');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks whether MariaDB’s query cache is enabled, which serialises writes behind a global lock.');
    }

    public function isAvailable(CheckContext $context): bool
    {
        // MySQL 8 removed the query cache entirely, so the variables don't exist there.
        return parent::isAvailable($context)
            && $context->database->variable('query_cache_type') !== null;
    }

    public function unavailableReason(CheckContext $context): string
    {
        if ($context->database->isMysql() && !$context->database->isMariaDb()) {
            return Craft::t('microscope', 'MySQL 8 removed the query cache, so there is nothing to check.');
        }

        return parent::unavailableReason($context);
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $type = $db->variable('query_cache_type');
        $size = $db->intVariable('query_cache_size');

        $enabled = $type !== null
            && !in_array(strtoupper($type), ['OFF', '0'], true)
            && ($size ?? 0) > 0;

        if (!$enabled) {
            return [$this->pass(
                Craft::t('microscope', 'Query cache is off'),
                Craft::t('microscope', 'Disabled, as recommended'),
            )];
        }

        $lowmemPrunes = $db->statusValue('qcache_lowmem_prunes');
        $hits = $db->statusValue('qcache_hits');
        $inserts = $db->statusValue('qcache_inserts');

        $evidence = [];

        if ($hits !== null && $inserts !== null && $inserts > 0) {
            $evidence[] = [
                'metric' => 'Hit ratio',
                'value' => number_format($hits / max($hits + $inserts, 1) * 100, 1) . '%',
            ];
        }

        if ($lowmemPrunes !== null) {
            $evidence[] = ['metric' => 'Low-memory prunes', 'value' => number_format($lowmemPrunes)];
        }

        return [$this->warning([
            'title' => Craft::t('microscope', 'The query cache is enabled'),
            'summary' => Craft::t('microscope', 'MariaDB’s query cache is on with {size} allocated. On a site with any write traffic it costs more than it saves.', [
                'size' => Bytes::format($size),
            ]),
            'observed' => Craft::t('microscope', 'query_cache_type={type}, {size}', [
                'type' => $type,
                'size' => Bytes::format($size),
            ]),
            'expected' => 'OFF',
            'target' => 'query_cache_type',
            'evidence' => $evidence,
            'impact' => Craft::t('microscope', 'Every write to a table invalidates every cached query referencing it, and the invalidation takes a server-wide mutex. Under concurrency that mutex becomes the bottleneck — the cache actively makes the database slower, which is why MySQL 8 removed it altogether.'),
            'remediation' => <<<'MD'
Turn it off. This isn't a judgement call — MySQL deleted the feature, and MariaDB's own
documentation recommends against it for anything but a read-only workload.

```ini
[mysqld]
query_cache_type = 0
query_cache_size = 0
```

Both values matter: `query_cache_type = 0` stops new entries but leaves the memory allocated and
the mutex in play, so set the size to zero as well.

Applying it needs a restart — `query_cache_type` is read-only at runtime — though you can free the
memory immediately with:

```sql
SET GLOBAL query_cache_size = 0;
```

Craft caches at a much better layer anyway: `{% cache %}` in templates and the data cache for
element queries both invalidate precisely, rather than throwing away everything that touched a
table.
MD,
            'docsUrl' => 'https://mariadb.com/kb/en/query-cache/',
        ])];
    }
}
