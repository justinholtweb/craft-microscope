<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\TemplateAnalysis\Rules;

use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\TemplateAnalysis\BaseTemplateRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateSource;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateStructure;

use function CraftCms\Cms\t;

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
        return t('Includes inside loops inherit the full context', category: 'microscope');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return t('Without `only`, Twig copies every variable in scope into the included template on each iteration. In a loop that is a repeated array copy of whatever the page happens to be holding — often entire fetched result sets — and it also means the partial silently depends on variables nobody passed it.', category: 'microscope');
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
            filter: function (int $offset, array $match) use ($structure) {
                if (! $structure->isInsideLoopAt($offset)) {
                    return false;
                }

                return ! preg_match('/\bonly\b/', $match[2]);
            },
            detail: fn (int $offset, array $match) => sprintf('{%% %s %%} without `only`', $match[1]),
        );
    }
}
