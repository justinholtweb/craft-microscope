<?php

namespace justinholtweb\microscope\checks\schema;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * The scale of the content, and what that implies for everything else.
 *
 * Reports rather than judges, mostly. A site with 400,000 elements isn't doing anything wrong —
 * but it needs a buffer pool, a search strategy and template limits that a site with 400 doesn't,
 * and a report that says "you have 400,000 elements" gives every other finding its context.
 */
class ContentVolumeCheck extends BaseCheck
{
    private const LARGE_SITE = 50000;
    private const VERY_LARGE_SITE = 250000;

    public function handle(): string
    {
        return 'contentVolume';
    }

    public function category(): string
    {
        return Category::SCHEMA;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Content volume');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Measures how much content the site holds, and flags the point at which unbounded queries stop being survivable.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $elements = $craft->elementCount();
        $sites = $craft->siteCount();
        $sections = count($craft->sections());
        $entryTypes = count($craft->entryTypes());

        $summary = Craft::t('microscope', '{elements} elements across {sections} sections and {sites} sites', [
            'elements' => number_format($elements),
            'sections' => $sections,
            'sites' => $sites,
        ]);

        if ($elements < self::LARGE_SITE) {
            return [$this->pass(
                Craft::t('microscope', 'Content volume is modest'),
                $summary,
                Craft::t('microscope', 'At this scale most query patterns are survivable even when they aren’t ideal.'),
            )];
        }

        $severity = $elements >= self::VERY_LARGE_SITE ? 'warning' : 'notice';

        return [$this->finding($severity, [
            'title' => Craft::t('microscope', 'This is a large Craft install'),
            'summary' => $summary,
            'observed' => number_format($elements) . Craft::t('microscope', ' elements'),
            'expected' => Craft::t('microscope', 'Not a fault — a threshold'),
            'target' => 'schema.contentVolume',
            'evidence' => [
                ['metric' => Craft::t('microscope', 'Live elements'), 'value' => number_format($elements)],
                ['metric' => Craft::t('microscope', 'Sections'), 'value' => (string)$sections],
                ['metric' => Craft::t('microscope', 'Entry types'), 'value' => (string)$entryTypes],
                ['metric' => Craft::t('microscope', 'Sites'), 'value' => (string)$sites],
                ['metric' => Craft::t('microscope', 'Revisions'), 'value' => number_format($craft->revisionCount())],
            ],
            'impact' => Craft::t('microscope', 'Nothing here is wrong. It is reported because past this size the tolerances change: an element query without a limit, a search across every field, or a buffer pool sized for a brochure site all stop being survivable. Read the rest of this report with that in mind.'),
            'remediation' => $this->guidance($sites),
            'docsUrl' => 'https://craftcms.com/docs/5.x/development/element-queries.html',
        ])];
    }

    private function guidance(int $sites): string
    {
        $multiSiteNote = $sites > 1
            ? "\n\n**Multi-site multiplies everything.** With {$sites} sites, each element exists once in `elements` "
            . "and once per site in `elements_sites`, and saving one entry propagates to all of them. If some "
            . "sections don't need to exist in every site, set their propagation method to something narrower "
            . "than *Save entries to all sites* — it's the largest single saving available on a large multi-site "
            . "install."
            : '';

        return <<<MD
At this scale, four things matter more than they would on a smaller site:

**1. Every element query needs a `.limit()`.** An unbounded `.all()` that returns a whole section
will exhaust PHP's memory rather than merely being slow. Microscope's template category lists the
ones it can find statically.

**2. Eager-load relations, always.** N+1 queries that are invisible at 500 elements are the whole
response time at 250,000.

**3. Reconsider search.** Craft's `searchindex` uses `LIKE '%term%'`, which cannot use an index —
its cost grows linearly with the table. If search matters on a site this size, move it to a
dedicated engine. Otherwise, cut what's indexed: go through **Settings → Fields** and turn off
"Use this field's values as search keywords" for everything you don't actually search on, then:

```bash
php craft resave/entries --update-search-index
```

**4. Size the database for the data.** The InnoDB buffer pool needs to hold the working set;
see the database section of this report for the specific numbers.{$multiSiteNote}

**Bulk operations need care too.** `resave/entries` across a section this large should be run in
batches, out of hours, from the console rather than the control panel:

```bash
php craft resave/entries --section=news --offset=0 --limit=5000
```
MD;
    }
}
