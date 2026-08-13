<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * A new element query started inside a `{% for %}` loop.
 *
 * This is the single most expensive mistake it is possible to make in a Craft template, and the
 * one that most reliably explains "the site is fine locally but slow in production": the query
 * count scales with the number of rows, so it looks harmless on a page with three entries and
 * falls over on a page with three hundred.
 */
class QueryInLoopRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'queryInLoop';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Element queries run inside a loop');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_CRITICAL;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'Each iteration runs its own database query, so a page rendering 100 rows makes 100 extra round trips instead of one. This is the classic N+1 problem: response time grows with the amount of content, which is exactly when you can least afford it.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Fetch everything up front, then loop over what you already have.

Where the loop is over elements and the inner query pulls a **relation**, use eager loading — one
extra query for the whole set instead of one per row:

```twig
{# Before: one query per entry #}
{% for entry in craft.entries().section('news').all() %}
  {% for image in craft.assets().id(entry.heroImage.ids()).all() %}…{% endfor %}
{% endfor %}

{# After: two queries total #}
{% set entries = craft.entries().section('news').with(['heroImage']).all() %}
{% for entry in entries %}
  {% for image in entry.heroImage %}…{% endfor %}
{% endfor %}
```

Where the inner query isn't a relation — a lookup by some field value, say — pull the whole set
once before the loop and index it:

```twig
{% set authors = craft.users().id(entries|map(e => e.authorId))|all %}
{% set byId = authors|column(null, 'id') %}
{% for entry in entries %}
  {{ byId[entry.authorId].fullName }}
{% endfor %}
```

If neither applies and the data genuinely has to be fetched per row, wrap the block in
`{% cache %}` so the cost is paid once per cache period rather than on every request.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/development/eager-loading.html';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/\bcraft\.(entries|assets|users|categories|tags|addresses|globalSets|matrixBlocks|query)\s*[\(\.]/',
            $source,
            $structure,
            filter: fn(int $offset) => $structure->isInsideLoopAt($offset),
            detail: function(int $offset, array $match) use ($structure) {
                $depth = $structure->loopDepthAt($offset);
                $loop = $structure->enclosingLoopLine($offset);

                return sprintf(
                    'craft.%s, %d %s deep (loop opens on line %d)',
                    $match[1],
                    $depth,
                    $depth === 1 ? 'loop' : 'loops',
                    $loop ?? 0,
                );
            },
        );
    }
}
