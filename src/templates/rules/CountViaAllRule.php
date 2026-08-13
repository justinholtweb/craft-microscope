<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * Counting elements by fetching them all and measuring the array.
 */
class CountViaAllRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'countViaAll';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Elements fetched only to be counted');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return Craft::t('microscope', '`.all()|length` selects, hydrates and instantiates every matching element — content, relations and all — purely to read the size of the resulting array. `.count()` asks the database for a single number instead. On a section with a few thousand entries the difference is several megabytes of memory and a noticeable slice of the response.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Swap the fetch-then-measure for a count query:

```twig
{# Before #}
{{ craft.entries().section('news').all()|length }}

{# After #}
{{ craft.entries().section('news').count() }}
```

If you only need to know *whether* there are any, `.exists()` is cheaper still — it stops at the
first row rather than counting every one:

```twig
{% if craft.entries().section('news').exists() %}…{% endif %}
```

Where the elements are needed anyway, keep `.all()`, assign it to a variable, and take `|length`
of that — the mistake is only fetching twice or fetching purely to count.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/development/element-queries.html#count';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/\.all\(\s*\)\s*\|\s*length\b/',
            $source,
            $structure,
            detail: fn() => 'use .count() instead of .all()|length',
        );
    }
}
