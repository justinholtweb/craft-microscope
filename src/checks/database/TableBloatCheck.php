<?php

namespace justinholtweb\microscope\checks\database;

use Craft;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;

/**
 * Craft's own tables getting fat.
 *
 * The tables in this check are the ones that grow without anybody deciding they should: revisions
 * accumulate on every save, the search index grows with every field, and sessions are never
 * cleaned up by anything except Craft's garbage collector. On a site that's been running for a few
 * years these routinely outweigh the actual content several times over — which matters because
 * they're competing for the same buffer pool as the content.
 */
class TableBloatCheck extends BaseDatabaseCheck
{
    /**
     * Tables worth watching, with the size at which each becomes interesting and a note on where
     * the growth comes from.
     */
    private const WATCHED = [
        'searchindex' => [256 * 1024 * 1024, 'search index'],
        'revisions' => [128 * 1024 * 1024, 'entry revisions'],
        'elements' => [256 * 1024 * 1024, 'elements'],
        'sessions' => [64 * 1024 * 1024, 'user sessions'],
        'changedfields' => [64 * 1024 * 1024, 'field change tracking'],
        'changedattributes' => [64 * 1024 * 1024, 'attribute change tracking'],
        'queue' => [32 * 1024 * 1024, 'the job queue'],
    ];

    public function handle(): string
    {
        return 'tableBloat';
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Table sizes');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Looks for Craft tables that have grown out of proportion to the site’s actual content.');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $findings = [];
        $total = $db->totalSize();

        if ($total === 0) {
            return [];
        }

        $oversized = [];

        foreach (self::WATCHED as $table => [$threshold, $description]) {
            $size = $db->tableSize($table);

            if ($size === 0) {
                continue;
            }

            $share = $size / $total;

            // Interesting either because it's large in absolute terms, or because it dominates the
            // database regardless of size — a 40 MB searchindex on a 50 MB database is as much of
            // a signal as a 400 MB one on a 2 GB database.
            if ($size >= $threshold || $share >= 0.35) {
                $oversized[] = [
                    'table' => $table,
                    'description' => $description,
                    'size' => Bytes::format($size),
                    'rows' => number_format($db->tableRows($table)),
                    'share' => number_format($share * 100, 1) . '%',
                ];
            }
        }

        if ($oversized !== []) {
            $names = implode(', ', array_column($oversized, 'table'));

            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'Some Craft tables are disproportionately large'),
                'summary' => Craft::t('microscope', '{names} take up an unusual share of a {total} database.', [
                    'names' => $names,
                    'total' => Bytes::format($total),
                ]),
                'observed' => $names,
                'expected' => Craft::t('microscope', 'Bookkeeping smaller than the content'),
                'target' => 'database.tables',
                'occurrences' => count($oversized),
                'evidence' => $oversized,
                'impact' => Craft::t('microscope', 'These tables hold bookkeeping rather than content, and they compete with the content for the same buffer pool. A search index or revision table larger than the entries themselves means most of the memory the database has is caching data no visitor will ever see.'),
                'remediation' => $this->instructions($context),
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#maxrevisions',
            ]);
        }

        // --- storage engine ------------------------------------------------
        $myisam = $db->nonInnodbTables();

        if ($myisam !== []) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'Some tables are not using InnoDB'),
                'summary' => Craft::t('microscope', '{count} tables use a storage engine other than InnoDB.', ['count' => count($myisam)]),
                'observed' => implode(', ', array_slice($myisam, 0, 5)) . (count($myisam) > 5 ? '…' : ''),
                'expected' => 'InnoDB',
                'target' => 'database.engine',
                'occurrences' => count($myisam),
                'evidence' => array_map(fn(string $t) => ['table' => $t], $myisam),
                'impact' => Craft::t('microscope', 'MyISAM locks the entire table for every write, has no transactions, and doesn’t use the InnoDB buffer pool — so all the memory tuning in this report simply doesn’t apply to it. Under concurrent writes it serialises the whole site behind one lock.'),
                'remediation' => "Convert them:\n\n```sql\nALTER TABLE `tablename` ENGINE = InnoDB;\n```\n\nThis rewrites the table, so take a backup first and do it during a quiet period — the table is locked for the duration, which on a large table can be minutes.\n\nTo generate the statements for every affected table:\n\n```sql\nSELECT CONCAT('ALTER TABLE `', TABLE_NAME, '` ENGINE=InnoDB;')\nFROM information_schema.TABLES\nWHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB';\n```\n\nIf any of these are plugin tables, check the plugin isn't recreating them as MyISAM on install.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Table sizes are proportionate'),
                Bytes::format($total) . Craft::t('microscope', ' total'),
            );
        }

        return $findings;
    }

    private function instructions(CheckContext $context): string
    {
        $maxRevisions = $context->craft->maxRevisions();
        $revisionNote = $maxRevisions === null
            ? "`maxRevisions` is not set, so Craft keeps **every revision forever**. This is the single most common cause of a bloated Craft database."
            : "`maxRevisions` is set to **{$maxRevisions}**.";

        return <<<MD
Deal with each table according to what fills it.

**`revisions` / `elements`** — {$revisionNote}

```php
// config/general.php
'maxRevisions' => 10,
```

Setting it doesn't retroactively delete anything; Craft prunes down to the limit as each element
is next saved. To force the cleanup now:

```bash
php craft gc --delete-all-trashed
```

**`searchindex`** — grows with every searchable field on every element in every site. Most sites
have far more fields marked searchable than they ever search on. Go through
**Settings → Fields** and turn "Use this field's values as search keywords" off for anything you
don't search, then rebuild:

```bash
php craft resave/entries --update-search-index
```

If you don't use Craft's search at all, disable indexing entirely and the table stops growing.

**`sessions`** — cleaned by Craft's garbage collector, which only runs if something triggers it.
Make sure `gc` runs on a schedule:

```bash
# crontab
0 4 * * * cd /path/to/site && php craft gc
```

**`changedfields` / `changedattributes`** — these power "what changed in this revision". They are
pruned along with revisions, so fixing `maxRevisions` fixes these too.

**`queue`** — completed jobs are removed, but failed ones stay so you can inspect them. Clear them
from **Utilities → Queue Manager**, or:

```bash
php craft queue/release all
```

**Afterwards, reclaim the disk space.** Deleting rows from InnoDB doesn't shrink the file:

```sql
OPTIMIZE TABLE searchindex, revisions, sessions;
```

This locks each table while it rebuilds — run it in a maintenance window, not on a live site.
MD;
    }
}
