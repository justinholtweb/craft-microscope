<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Database;

use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Support\Bytes;

use function CraftCms\Cms\t;

/**
 * Temporary tables spilling to disk.
 *
 * Craft's element queries sort and group across joined tables constantly, which makes temporary
 * tables routine. In memory they're cheap; on disk they're a file create, a write and a delete per
 * query. The ratio between the two is one of the most reliable indicators of a badly tuned MySQL.
 */
class TempTableCheck extends BaseDatabaseCheck
{
    private const RECOMMENDED = 64 * 1024 * 1024;

    /**
     * The share of temporary tables allowed to hit disk before it's worth reporting. Some always
     * will — a query with a BLOB or TEXT column in the result can't use an in-memory temp table at
     * all on older servers, whatever the size limits say.
     */
    private const ACCEPTABLE_DISK_RATIO = 0.25;

    public function handle(): string
    {
        return 'tempTables';
    }

    public function label(): string
    {
        return t('Temporary tables', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks how often the database has to write temporary tables to disk while sorting and grouping.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $findings = [];

        $tmpTableSize = $db->intVariable('tmp_table_size');
        $maxHeapTableSize = $db->intVariable('max_heap_table_size');

        // The effective limit is the smaller of the two — raising one alone does nothing, which is
        // the single most common mistake made with this setting.
        $effective = min($tmpTableSize ?? PHP_INT_MAX, $maxHeapTableSize ?? PHP_INT_MAX);

        if ($this->hasMeaningfulStatistics($context)) {
            $created = $db->statusValue('created_tmp_tables');
            $onDisk = $db->statusValue('created_tmp_disk_tables');

            if ($created !== null && $onDisk !== null && $created > 1000) {
                $ratio = $onDisk / max($created, 1);

                if ($ratio > self::ACCEPTABLE_DISK_RATIO) {
                    $findings[] = $this->warning([
                        'title' => t('Temporary tables are spilling to disk', category: 'microscope'),
                        'summary' => t('{percent}% of temporary tables are being written to disk — {onDisk} of {created} since the server started.', [
                            'percent' => number_format($ratio * 100, 1),
                            'onDisk' => number_format($onDisk),
                            'created' => number_format($created),
                        ], category: 'microscope'),
                        'observed' => number_format($ratio * 100, 1).'% on disk',
                        'expected' => t('Under {percent}%', ['percent' => (int) (self::ACCEPTABLE_DISK_RATIO * 100)], category: 'microscope'),
                        'target' => 'tmp_table_size',
                        'impact' => t('A disk-backed temporary table turns an in-memory sort into filesystem I/O inside the query. Craft hits this on any listing that sorts or groups across a join — which is most of them — so the cost lands on exactly the pages visitors wait for.', category: 'microscope'),
                        'remediation' => $this->instructions($effective, $tmpTableSize, $maxHeapTableSize),
                    ]);
                }
            }
        }

        if ($effective !== PHP_INT_MAX && $effective < self::RECOMMENDED && $findings === []) {
            $findings[] = $this->notice([
                'title' => t('In-memory temporary table limit is low', category: 'microscope'),
                'summary' => t('Temporary tables larger than {observed} are written to disk.', [
                    'observed' => Bytes::format($effective),
                ], category: 'microscope'),
                'observed' => Bytes::format($effective),
                'expected' => Bytes::format(self::RECOMMENDED),
                'target' => 'tmp_table_size',
                'impact' => t('Nothing is going wrong yet, but the ceiling is low enough that a sort over a large section will cross it.', category: 'microscope'),
                'remediation' => $this->instructions($effective, $tmpTableSize, $maxHeapTableSize),
            ]);
        }

        // Sort buffer is the other half of the same story.
        $sortMerges = $db->statusValue('sort_merge_passes');

        if ($sortMerges !== null && $sortMerges > 10000 && $this->hasMeaningfulStatistics($context)) {
            $findings[] = $this->notice([
                'title' => t('Sorts are being merged through disk', category: 'microscope'),
                'summary' => t('{count} sort merge passes have happened, meaning sorts didn’t fit in `sort_buffer_size`.', [
                    'count' => number_format($sortMerges),
                ], category: 'microscope'),
                'observed' => number_format($sortMerges).' merge passes',
                'expected' => t('Near zero', category: 'microscope'),
                'target' => 'sort_buffer_size',
                'impact' => t('Each merge pass is a multi-stage disk sort inside a query that was supposed to happen in memory.', category: 'microscope'),
                'remediation' => "```ini\n[mysqld]\nsort_buffer_size = 4M\n```\n\nBe careful with this one: `sort_buffer_size` is allocated **per connection performing a sort**, not globally. Setting it to 256 MB on a server with 200 connections is a way to run out of memory, not a way to go faster. 2–8 MB is the sane range; raise it in small steps and watch `Sort_merge_passes`.\n\nMore often, a high merge count means a query is sorting a large result set that an index could have returned already ordered. Craft's `orderBy` on a custom field is the usual cause — the search and sort happen in different tables, so no index covers both.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Temporary tables are staying in memory', category: 'microscope'),
                Bytes::format($effective === PHP_INT_MAX ? null : $effective),
            );
        }

        return $findings;
    }

    private function instructions(int $effective, ?int $tmpTableSize, ?int $maxHeapTableSize): string
    {
        $current = Bytes::format($effective === PHP_INT_MAX ? null : $effective);
        $tmp = Bytes::format($tmpTableSize);
        $heap = Bytes::format($maxHeapTableSize);

        return <<<MD
**Both settings have to move together.** MySQL uses the *smaller* of the two, so raising one on its
own achieves nothing — and that is the mistake almost everybody makes here.

Currently: `tmp_table_size` = {$tmp}, `max_heap_table_size` = {$heap}, so the effective limit is
**{$current}**.

```ini
[mysqld]
tmp_table_size = 64M
max_heap_table_size = 64M
```

Both can be changed without a restart:

```sql
SET GLOBAL tmp_table_size = 67108864;
SET GLOBAL max_heap_table_size = 67108864;
```

**Don't go much beyond 64–128 MB.** This is a per-connection allocation, so the worst case is the
value multiplied by the number of connections sorting at once.

**Then check whether the queries are the real problem.** Some temporary tables will always hit
disk regardless of size — historically any result containing a `TEXT` or `BLOB` column forced it,
which in Craft means anything selecting rich text. To see what's actually happening:

```sql
SHOW GLOBAL STATUS LIKE 'Created_tmp%';
```

and, for a specific slow page, `EXPLAIN` the query — `Using temporary; Using filesort` in the
Extra column is what you're looking for. Craft's Debug Toolbar (enable `devMode` locally) shows
every query a page ran, which is the fastest way to find the offender.
MD;
    }
}
