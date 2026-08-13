<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateIssue;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * An element query with no `.limit()`, so it grows with the site.
 *
 * Craft's element queries return everything by default. A template written against a section with
 * twelve entries keeps working as that section reaches twelve thousand — it just gets slower every
 * month, which makes it hard to attribute to any particular change.
 */
class UnboundedQueryRule extends BaseTemplateRule
{
    /**
     * How far past `craft.x` to look for the terminating call. Long enough for a chained,
     * multi-line query; short enough not to run into the next statement in the file.
     */
    private const LOOKAHEAD = 600;

    public function handle(): string
    {
        return 'unboundedQuery';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Element queries with no limit');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_WARNING;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'The query returns every matching element, however many that turns out to be. Memory use and render time then track the size of the content rather than the size of the page, and the template gets gradually slower as the site fills up without any code changing.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Give every query an explicit ceiling, even when you expect the set to be small:

```twig
{% set posts = craft.entries()
  .section('news')
  .limit(12)
  .all() %}
```

Where the page genuinely has to show everything, paginate it rather than rendering it all at once:

```twig
{% paginate craft.entries().section('news').limit(25) as pageInfo, posts %}
{% for post in posts %}…{% endfor %}
{{ pageInfo.getPrevUrl() }} {{ pageInfo.getNextUrl() }}
```

A limit is also a safety net against a filter that silently stops matching: an unlimited query
with a broken `.section()` returns the whole site, where a limited one returns twelve rows of the
wrong thing and gets noticed.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/development/element-queries.html#limit';
    }

    /**
     * Walks each `craft.<type>` occurrence forward to the call that executes it, rather than
     * trying to express a chained multi-line query as one regex.
     */
    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        $masked = $source->masked();

        if (!preg_match_all('/\bcraft\.(entries|assets|users|categories|tags|addresses)\s*[\(\.]/', $masked, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $issues = [];
        $seenLines = [];

        foreach ($matches as $match) {
            $offset = (int)$match[0][1];
            $line = $source->lineAt($offset);

            if ($structure->isInsideVerbatimAt($offset) || isset($seenLines[$line])) {
                continue;
            }

            $chain = substr($masked, $offset, self::LOOKAHEAD);

            // Only the terminating `.all()` is unbounded. `.one()`, `.count()`, `.exists()` and
            // `.nth()` all constrain themselves, and a query assigned without executing might be
            // limited by whatever consumes it.
            $end = strpos($chain, '.all(');

            if ($end === false) {
                continue;
            }

            $chain = substr($chain, 0, $end);

            if (preg_match('/\.(limit|id|slug|uri|nth)\s*\(/', $chain)) {
                continue;
            }

            // `{% paginate %}` supplies its own limit per page.
            if (str_contains($source->line($line), 'paginate')) {
                continue;
            }

            $seenLines[$line] = true;

            $issues[] = new TemplateIssue(
                rule: $this->handle(),
                templatePath: $source->path,
                line: $line,
                snippet: $source->snippet($line),
                detail: sprintf('craft.%s(…).all() with no .limit()', $match[1][0]),
                cached: $structure->isInsideCacheAt($offset),
            );
        }

        return $issues;
    }
}
