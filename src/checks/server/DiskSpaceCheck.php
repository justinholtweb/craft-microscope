<?php

namespace justinholtweb\microscope\checks\server;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

/**
 * Free space on the volume the site lives on.
 *
 * Not a performance setting so much as a cliff. Craft writes compiled templates, image transforms,
 * logs and session files continuously; when the disk fills, all of that starts failing at once and
 * the failure modes are confusing — blank pages from a template that can't compile, missing
 * images, and a database that has stopped accepting writes.
 */
class DiskSpaceCheck extends BaseCheck
{
    private const WARNING_SHARE = 0.15;
    private const CRITICAL_SHARE = 0.07;

    public function handle(): string
    {
        return 'diskSpace';
    }

    public function category(): string
    {
        return Category::SERVER;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Disk space');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks free space on the volume holding the site.');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->server->freeDiskSpace() !== null;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return Craft::t('microscope', 'PHP can’t read filesystem statistics here — `disk_free_space()` is often blocked by open_basedir on shared hosting.');
    }

    public function run(CheckContext $context): array
    {
        $free = $context->server->freeDiskSpace();
        $total = $context->server->totalDiskSpace();

        if ($free === null || $total === null || $total === 0) {
            return [];
        }

        $share = $free / $total;

        if ($share < self::WARNING_SHARE) {
            $severity = $share < self::CRITICAL_SHARE ? 'critical' : 'warning';

            return [$this->finding($severity, [
                'title' => Craft::t('microscope', 'The disk is nearly full'),
                'summary' => Craft::t('microscope', '{free} free of {total} — {percent}%.', [
                    'free' => Bytes::format($free),
                    'total' => Bytes::format($total),
                    'percent' => number_format($share * 100, 1),
                ]),
                'observed' => Bytes::format($free) . ' free (' . number_format($share * 100, 1) . '%)',
                'expected' => Craft::t('microscope', 'At least 15% free'),
                'target' => 'server.disk',
                'impact' => Craft::t('microscope', 'A full disk breaks everything at once and in confusing ways: Twig can’t write compiled templates so pages go blank, image transforms fail silently, sessions stop persisting so editors are logged out mid-edit, and MySQL refuses writes. InnoDB also needs free space for its own housekeeping well before the disk is actually full.'),
                'remediation' => $this->instructions(),
            ])];
        }

        return [$this->pass(
            Craft::t('microscope', 'Disk space is comfortable'),
            Bytes::format($free) . Craft::t('microscope', ' free of ') . Bytes::format($total),
        )];
    }

    private function instructions(): string
    {
        return <<<'MD'
Find the space first:

```bash
df -h
du -sh /path/to/site/* | sort -rh | head -10
du -sh /path/to/site/storage/* | sort -rh | head -10
```

In a Craft install the space is nearly always in one of these:

**`storage/runtime/`** — compiled templates, caches and temporary files. Safe to clear entirely;
Craft rebuilds what it needs:

```bash
php craft clear-caches/all
rm -rf storage/runtime/compiled_templates/*
```

**`storage/logs/`** — Craft rotates these, but a site with `devMode` left on in production or a
plugin logging at debug level produces a lot. Check what's writing:

```bash
ls -lhS storage/logs/ | head
```

**Image transforms** — every named transform of every image, in every format. These live in your
asset volume under `_<transform>/` directories. They regenerate on demand, so deleting them is
safe but will cause a burst of CPU work afterwards:

```bash
php craft invalidate-asset-transforms
```

**Database backups** — `php craft db/backup` writes to `storage/backups/` and nothing prunes them.

```bash
ls -lht storage/backups/ | head
```

**Old releases**, if you deploy with a releases directory. Most deployment tools keep the last N;
check that N isn't unbounded.

Once you're under control, keep the runtime directories out of your backups and add a cron job for
Craft's own garbage collection:

```bash
0 4 * * * cd /path/to/site && php craft gc
```
MD;
    }
}
