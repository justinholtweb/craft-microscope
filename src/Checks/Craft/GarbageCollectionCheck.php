<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * Whether Craft is throwing anything away.
 *
 * Revisions, drafts and soft-deleted elements all accumulate silently. With `maxRevisions` unset —
 * which is the default — Craft keeps every revision of every entry forever, and on a site with
 * active editors that becomes the largest thing in the database within a year or two.
 */
class GarbageCollectionCheck extends BaseCheck
{
    /**
     * Revisions per live element, above which the history clearly outweighs the content.
     */
    private const REVISION_RATIO_WARNING = 10;

    private const TRASHED_WARNING = 5000;

    public function handle(): string
    {
        return 'garbageCollection';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Garbage collection', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks revision limits, soft-deleted content and whether anything is actually cleaning up.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];

        $elements = $craft->elementCount();
        $revisions = $craft->revisionCount();
        $drafts = $craft->draftCount();
        $trashed = $craft->trashedCount();
        $maxRevisions = $craft->maxRevisions();

        $ratio = $elements > 0 ? $revisions / $elements : 0;

        if ($maxRevisions === null && $revisions > 1000) {
            $findings[] = $this->warning([
                'title' => t('Revisions are never pruned', category: 'microscope'),
                'summary' => t('`maxRevisions` isn’t set, so Craft keeps every revision forever. There are {revisions} revisions for {elements} live elements.', [
                    'revisions' => number_format($revisions),
                    'elements' => number_format($elements),
                ], category: 'microscope'),
                'observed' => t('maxRevisions unset, {count} revisions', ['count' => number_format($revisions)], category: 'microscope'),
                'expected' => t('A revision limit', category: 'microscope'),
                'target' => 'maxRevisions',
                'impact' => t('Every revision is a full copy of the entry — a row in `elements`, one per site in `elements_sites`, and every field value. They share the buffer pool with live content, so the database spends memory caching versions of pages that were replaced years ago.', category: 'microscope'),
                'remediation' => $this->revisionInstructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/reference/config/general.html#maxrevisions',
            ]);
        } elseif ($ratio > self::REVISION_RATIO_WARNING && $revisions > 1000) {
            $findings[] = $this->notice([
                'title' => t('There are many revisions per element', category: 'microscope'),
                'summary' => t('{ratio} revisions for every live element, with maxRevisions set to {max}.', [
                    'ratio' => number_format($ratio, 1),
                    'max' => $maxRevisions,
                ], category: 'microscope'),
                'observed' => number_format($revisions).t(' revisions', category: 'microscope'),
                'expected' => t('Roughly maxRevisions × elements', category: 'microscope'),
                'target' => 'maxRevisions',
                'impact' => t('The limit is set but there are more revisions than it implies, which means old ones haven’t been pruned yet — Craft prunes an element’s revisions when that element is next saved, so entries nobody has touched keep theirs indefinitely.', category: 'microscope'),
                'remediation' => $this->revisionInstructions(),
            ]);
        }

        if ($trashed > self::TRASHED_WARNING) {
            $findings[] = $this->notice([
                'title' => t('Soft-deleted content is piling up', category: 'microscope'),
                'summary' => t('{count} elements are in the trash awaiting permanent deletion.', [
                    'count' => number_format($trashed),
                ], category: 'microscope'),
                'observed' => number_format($trashed).t(' trashed', category: 'microscope'),
                'expected' => t('Cleared by scheduled garbage collection', category: 'microscope'),
                'target' => 'softDeleteDuration',
                'impact' => t('Trashed elements are still rows in `elements` and every content table, and every element query filters them out — so they cost index space and a condition on every query for content nobody can see.', category: 'microscope'),
                'remediation' => $this->gcInstructions($craft->softDeleteDuration()),
            ]);
        }

        if ($drafts > $elements && $elements > 0) {
            $findings[] = $this->notice([
                'title' => t('There are more drafts than live elements', category: 'microscope'),
                'summary' => t('{drafts} drafts against {elements} live elements.', [
                    'drafts' => number_format($drafts),
                    'elements' => number_format($elements),
                ], category: 'microscope'),
                'observed' => number_format($drafts).t(' drafts', category: 'microscope'),
                'expected' => t('Fewer drafts than entries', category: 'microscope'),
                'target' => 'drafts',
                'impact' => t('Most of these will be Craft’s automatic autosave drafts, created whenever an editor opens an entry and left behind if they navigate away. Garbage collection removes unsaved ones after `purgeUnsavedDraftsDuration`, but only if it runs.', category: 'microscope'),
                'remediation' => $this->gcInstructions($craft->softDeleteDuration()),
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('Content history is under control', category: 'microscope'),
                t('{elements} elements, {revisions} revisions, {trashed} trashed', [
                    'elements' => number_format($elements),
                    'revisions' => number_format($revisions),
                    'trashed' => number_format($trashed),
                ], category: 'microscope'),
            );
        }

        return $findings;
    }

    private function revisionInstructions(): string
    {
        return <<<'MD'
```php
// config/craft/general.php
return GeneralConfig::create()
    ->maxRevisions(10);
```

Ten is a reasonable default: enough to undo a bad edit, not enough to become an archive. Editors
who need more than that need a versioning workflow, not a bigger number.

**Setting it doesn't delete anything retroactively.** Craft prunes an element's revisions the next
time that element is saved, so entries nobody has edited since keep every revision they ever had.
To apply it across the site:

```bash
php artisan craft:resave:entries
```

That re-saves every entry, which triggers the prune. On a large site run it per section, and out of
hours — it's a lot of writes:

```bash
php artisan craft:resave:entries --section=news
```

**Then reclaim the space.** InnoDB doesn't return deleted rows to the filesystem on its own:

```sql
OPTIMIZE TABLE revisions, elements, elements_sites, changedfields, changedattributes;
```

This locks each table while it rebuilds — a maintenance window, not a live site.
MD;
    }

    private function gcInstructions(?int $softDeleteDuration): string
    {
        $current = $softDeleteDuration === null
            ? 'Soft deletes are currently kept **indefinitely** (`softDeleteDuration` is 0 or null), so nothing is ever purged.'
            : sprintf('Soft deletes are kept for **%d days** before purging.', (int) round($softDeleteDuration / 86400));

        return <<<MD
{$current}

**Craft's garbage collector runs by lottery**: on roughly ten in every million requests (and
console commands) it queues a garbage-collection job. A quiet site — or one whose queue isn't being
worked — can go weeks without one. Give it a schedule instead, with Laravel's scheduler:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('craft:gc:run')->dailyAt('04:00');
```

…and make sure the scheduler itself runs, from cron:

```bash
* * * * * cd /var/www/example.com && php artisan schedule:run >> /dev/null 2>&1
```

**Configure what it collects:**

```php
// config/craft/general.php
return GeneralConfig::create()
    ->softDeleteDuration(2592000)          // 30 days, then permanent
    ->purgeUnsavedDraftsDuration(2592000)  // abandoned autosave drafts
    ->purgePendingUsersDuration(2592000);  // never-activated accounts
```

Setting any of these to `0` means "keep forever", which is what an unset `softDeleteDuration`
amounts to.

**To clean up now**, including everything currently in the trash:

```bash
php artisan craft:gc:run --deleteAllTrashed
```

Take a backup first — this is the one command in this report that permanently destroys content,
and "empty the trash" is only reversible from a backup.
MD;
    }
}
