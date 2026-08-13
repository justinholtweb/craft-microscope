<?php

namespace justinholtweb\microscope\checks\database;

use Craft;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;

/**
 * The InnoDB buffer pool, which is the single most consequential database setting there is.
 *
 * It's the cache InnoDB keeps of data and index pages. If it's big enough to hold the working set,
 * queries are served from memory; if it isn't, they go to disk. The default is 128 MB — a value
 * chosen so MySQL starts on a laptop, not one anybody should run a site on.
 */
class InnodbBufferPoolCheck extends BaseDatabaseCheck
{
    /**
     * Below this, the setting is certainly still at (or near) its shipped default.
     */
    private const DEFAULT_SIZE = 128 * 1024 * 1024;

    /**
     * How much bigger than the data the pool ideally is, so indexes and working pages fit too.
     */
    private const HEADROOM = 1.25;

    public function handle(): string
    {
        return 'innodbBufferPool';
    }

    public function label(): string
    {
        return Craft::t('microscope', 'InnoDB buffer pool');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Compares the InnoDB buffer pool against the size of Craft’s data, and checks how often pages are being read from disk.');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $pool = $db->intVariable('innodb_buffer_pool_size');
        $dataSize = $db->totalSize();

        if ($pool === null) {
            return [];
        }

        $findings = [];
        $target = (int)($dataSize * self::HEADROOM);

        if ($dataSize > 0 && $pool < $target) {
            $ratio = $pool / max($target, 1);

            $findings[] = $this->finding($this->severityForRatio($ratio), [
                'title' => Craft::t('microscope', 'InnoDB buffer pool is smaller than the database'),
                'summary' => Craft::t('microscope', 'The pool is {pool} and Craft’s tables occupy {data}. The database can’t be held in memory, so queries fall through to disk.', [
                    'pool' => Bytes::format($pool),
                    'data' => Bytes::format($dataSize),
                ]),
                'observed' => Bytes::format($pool),
                'expected' => Bytes::format($target) . ' or more',
                'target' => 'innodb_buffer_pool_size',
                'impact' => Craft::t('microscope', 'Every page not in the pool is a disk read. A Craft listing page joins half a dozen tables, so a cold buffer pool turns one page render into hundreds of reads — the difference between a query taking microseconds and taking milliseconds, multiplied by every query on the page.'),
                'remediation' => $this->instructions($pool, $dataSize, $context),
                'docsUrl' => 'https://dev.mysql.com/doc/refman/8.0/en/innodb-buffer-pool-resize.html',
            ]);
        } elseif ($pool <= self::DEFAULT_SIZE) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'InnoDB buffer pool is at its default size'),
                'summary' => Craft::t('microscope', 'The pool is {pool}, which is MySQL’s out-of-the-box value rather than one chosen for this server.', [
                    'pool' => Bytes::format($pool),
                ]),
                'observed' => Bytes::format($pool),
                'expected' => Craft::t('microscope', 'Sized for the machine’s memory'),
                'target' => 'innodb_buffer_pool_size',
                'impact' => Craft::t('microscope', 'The database currently fits, so this isn’t hurting yet — but 128 MB is the value MySQL ships so it will start anywhere, and the site will grow into a problem rather than announcing one.'),
                'remediation' => $this->instructions($pool, $dataSize, $context),
            ]);
        }

        // --- how it's actually performing --------------------------------
        if ($this->hasMeaningfulStatistics($context)) {
            $reads = $db->statusValue('innodb_buffer_pool_reads');
            $requests = $db->statusValue('innodb_buffer_pool_read_requests');

            if ($reads !== null && $requests !== null && $requests > 100000) {
                $hitRate = (1 - ($reads / max($requests, 1))) * 100;

                if ($hitRate < 99.0) {
                    $findings[] = $this->warning([
                        'title' => Craft::t('microscope', 'InnoDB is reading from disk regularly'),
                        'summary' => Craft::t('microscope', 'The buffer pool is satisfying {rate}% of page requests from memory.', [
                            'rate' => number_format($hitRate, 2),
                        ]),
                        'observed' => number_format($hitRate, 2) . '%',
                        'expected' => '99.9%+',
                        'target' => 'innodb_buffer_pool.hit_rate',
                        'impact' => Craft::t('microscope', 'This is the measured consequence rather than a projection: {reads} page reads have gone to disk since the server started. On a well-sized pool this number stays near zero once the cache is warm.', [
                            'reads' => number_format($reads),
                        ]),
                        'remediation' => $this->instructions($pool, $dataSize, $context),
                    ]);
                } elseif ($findings === []) {
                    $findings[] = $this->pass(
                        Craft::t('microscope', 'InnoDB buffer pool is serving from memory'),
                        Bytes::format($pool) . sprintf(', %.2f%% hit rate', $hitRate),
                    );
                }
            }
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'InnoDB buffer pool is large enough'),
                Bytes::format($pool) . Craft::t('microscope', ' for {data} of data', ['data' => Bytes::format($dataSize)]),
            );
        }

        return $findings;
    }

    private function instructions(int $current, int $dataSize, CheckContext $context): string
    {
        $suggested = max(
            self::DEFAULT_SIZE * 2,
            (int)($dataSize * self::HEADROOM),
        );

        // Where the machine's memory is knowable, cap the suggestion at a share of it — advice to
        // allocate 8 GB on a 2 GB box is worse than no advice.
        $totalMemory = $context->server->totalMemory();
        $memoryNote = '';

        if ($totalMemory !== null) {
            $ceiling = (int)($totalMemory * 0.6);
            $suggested = min($suggested, $ceiling);
            $memoryNote = sprintf(
                "\n\nThis server has **%s** of RAM. The suggestion above is capped at 60%% of it, "
                . "which leaves room for PHP-FPM and the OS page cache. If the database runs on its "
                . "own machine, 70–80%% is the usual figure instead.",
                Bytes::format($totalMemory),
            );
        }

        $suggestedIni = Bytes::toIni($suggested);
        $currentFormatted = Bytes::format($current);
        $dataFormatted = Bytes::format($dataSize);

        return <<<MD
Craft's data is **{$dataFormatted}**; the pool is **{$currentFormatted}**. Set it to at least
**{$suggestedIni}**.{$memoryNote}

**MySQL 5.7+ and MariaDB 10.2+ can resize this without a restart:**

```sql
SET GLOBAL innodb_buffer_pool_size = {$suggested};
```

Make it permanent in `my.cnf` (usually `/etc/mysql/my.cnf` or a file under
`/etc/mysql/mysql.conf.d/`):

```ini
[mysqld]
innodb_buffer_pool_size = {$suggestedIni}
innodb_buffer_pool_instances = 4
```

`innodb_buffer_pool_instances` splits the pool to reduce lock contention. One instance per GB is
the usual guidance, and it does nothing below about 1 GB total.

**On managed hosting** (RDS, DigitalOcean Managed MySQL, Aiven) the buffer pool is set as a
proportion of the instance's memory and isn't directly editable — the equivalent action there is
to move to a larger instance, or to reduce the amount of data. Microscope's table bloat check will
tell you whether the data itself is the problem.

**Watch the effect** rather than assuming it worked:

```sql
SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read%';
```

`Innodb_buffer_pool_reads` (disk) should stay nearly flat while
`Innodb_buffer_pool_read_requests` (memory) climbs.
MD;
    }
}
