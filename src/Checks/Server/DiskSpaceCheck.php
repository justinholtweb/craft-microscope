<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Server;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Support\Bytes;

use function CraftCms\Cms\t;

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
        return t('Disk space', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks free space on the volume holding the site.', category: 'microscope');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->server->freeDiskSpace() !== null;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return t('PHP can’t read filesystem statistics here — `disk_free_space()` is often blocked by open_basedir on shared hosting.', category: 'microscope');
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
                'title' => t('The disk is nearly full', category: 'microscope'),
                'summary' => t('{free} free of {total} — {percent}%.', [
                    'free' => Bytes::format($free),
                    'total' => Bytes::format($total),
                    'percent' => number_format($share * 100, 1),
                ], category: 'microscope'),
                'observed' => Bytes::format($free).' free ('.number_format($share * 100, 1).'%)',
                'expected' => t('At least 15% free', category: 'microscope'),
                'target' => 'server.disk',
                'impact' => t('A full disk breaks everything at once and in confusing ways: Twig can’t write compiled templates so pages go blank, image transforms fail silently, sessions stop persisting so editors are logged out mid-edit, and MySQL refuses writes. InnoDB also needs free space for its own housekeeping well before the disk is actually full.', category: 'microscope'),
                'remediation' => $this->instructions(),
            ])];
        }

        return [$this->pass(
            t('Disk space is comfortable', category: 'microscope'),
            Bytes::format($free).t(' free of ', category: 'microscope').Bytes::format($total),
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
php artisan craft:clear-caches:all
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
rm -rf path/to/volume/_*/          # the transform directories
php artisan craft:clear-caches:transform-indexes
```

**Database backups** — `php artisan craft:db:backup` writes to `storage/backups/` and nothing prunes them.

```bash
ls -lht storage/backups/ | head
```

**Old releases**, if you deploy with a releases directory. Most deployment tools keep the last N;
check that N isn't unbounded.

Once you're under control, keep the runtime directories out of your backups and add a cron job for
Craft's own garbage collection:

```bash
0 4 * * * cd /path/to/site && php artisan craft:gc:run
```
MD;
    }
}
