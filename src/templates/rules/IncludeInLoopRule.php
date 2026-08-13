<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * `{% include %}` inside a loop without `only`.
 */
class IncludeInLoopRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'includeInLoop';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Includes inside loops inherit the full context');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'Without `only`, Twig copies every variable in scope into the included template on each iteration. In a loop that is a repeated array copy of whatever the page happens to be holding — often entire fetched result sets — and it also means the partial silently depends on variables nobody passed it.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Pass the partial exactly what it needs, and nothing else:

```twig
{# Before #}
{% for entry in entries %}
  {% include '_partials/card' with { entry: entry } %}
{% endfor %}

{# After #}
{% for entry in entries %}
  {% include '_partials/card' with { entry: entry } only %}
{% endfor %}
```

The `only` keyword is the whole fix. Beyond the copying, it makes the partial's inputs explicit:
if it turns out to need something else, you find out immediately rather than when it's included
somewhere the variable doesn't happen to exist.

For partials called many times in one request, a `{% macro %}` is cheaper still — macros don't
create a new template context at all:

```twig
{% import '_partials/cards' as cards %}
{% for entry in entries %}{{ cards.card(entry) }}{% endfor %}
```
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/reference/twig/tags.html#include';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/\{%-?\s*(include|embed)\b([^%]*?)-?%\}/s',
            $source,
            $structure,
            filter: function(int $offset, array $match) use ($structure) {
                if (!$structure->isInsideLoopAt($offset)) {
                    return false;
                }

                return !preg_match('/\bonly\b/', $match[2]);
            },
            detail: fn(int $offset, array $match) => sprintf('{%% %s %%} without `only`', $match[1]),
        );
    }
}
