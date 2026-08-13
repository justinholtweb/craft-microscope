<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateIssue;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * A query-heavy template with no `{% cache %}` anywhere in it.
 *
 * This one is advisory by design. Caching is a judgement call — a template full of queries that
 * renders per-user content should *not* be cached — so the rule reports the shape and leaves the
 * decision to a person, rather than pretending there is a right answer it can compute.
 */
class MissingCacheTagRule extends BaseTemplateRule
{
    /**
     * How many element queries make a template worth a second look.
     */
    private const THRESHOLD = 4;

    public function handle(): string
    {
        return 'missingCacheTag';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Query-heavy templates with no caching');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'These templates each run several element queries and cache none of the result. That work is repeated in full on every request, including the requests that are identical to the last one.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Wrap the expensive, non-personalised parts in `{% cache %}`. Craft stores the rendered HTML and
invalidates it automatically when any element the block touched is saved:

```twig
{% cache using key "homepage-featured" for 1 week %}
  {% for entry in craft.entries().section('news').limit(6).with(['heroImage']).all() %}
    …
  {% endfor %}
{% endcache %}
```

Before you do, check what's inside the block:

- **Don't cache anything user-specific** — a logged-in user's name, a cart, a personalised list.
  The first visitor's version gets served to everyone else.
- **Don't cache form markup with a CSRF token.** Move the `{{ csrfInput() }}` outside the block,
  or use `{% cache %}` around the parts that don't include it.
- Add `{% cache if craft.app.user.isGuest %}` when a block is only safe to cache for anonymous
  visitors.

If most pages on the site are anonymous and identical, static page caching in front of Craft (a
CDN, Varnish, or a plugin like Blitz) will beat template caching by a wide margin — `{% cache %}`
still boots Craft on every request.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/reference/twig/tags.html#cache';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        if ($structure->hasTag('cache')) {
            return [];
        }

        preg_match_all(
            '/\bcraft\.(entries|assets|users|categories|tags|addresses|globalSets)\s*[\(\.]/',
            $source->masked(),
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $offsets = array_column($matches[0], 1);

        if (count($offsets) < self::THRESHOLD) {
            return [];
        }

        $line = $source->lineAt((int)$offsets[0]);

        return [
            new TemplateIssue(
                rule: $this->handle(),
                templatePath: $source->path,
                line: $line,
                snippet: $source->snippet($line),
                detail: sprintf('%d element queries, no {%% cache %%} tag', count($offsets)),
            ),
        ];
    }
}
