<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * A relational field executed inside a loop.
 *
 * Distinct from {@see QueryInLoopRule}: there is no `craft.` here, just `entry.someField.all()`.
 * It reads like property access, which is exactly why it gets missed — but a relational field is
 * an unexecuted element query, and calling `.all()`, `.one()` or `.count()` on it inside a loop
 * runs it once per row.
 */
class RelationInLoopRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'relationInLoop';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Relational fields executed inside a loop');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_WARNING;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'A relational field is a query that hasn’t run yet. Executing it inside a loop costs one query per iteration, and because the syntax looks like ordinary property access the cost is easy to miss when reading the template.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Eager-load the relation on the query that feeds the loop. Craft then fetches every related element
in one query and hands each parent its own share.

```twig
{# Before: one query per entry, on every entry #}
{% for entry in craft.entries().section('news').all() %}
  {% for tag in entry.topics.all() %}{{ tag.title }}{% endfor %}
{% endfor %}

{# After #}
{% for entry in craft.entries().section('news').with(['topics']).all() %}
  {% for tag in entry.topics %}{{ tag.title }}{% endfor %}
{% endfor %}
```

Two details worth knowing:

- Once a relation is eager-loaded, drop the `.all()`. Calling it again re-runs the query and
  throws the eager-loaded result away.
- Nested relations use dotted paths: `.with(['topics', 'author.photo'])`.
- For a count only, `entry.topics.count()` is still a query per row — eager-load with
  `.with([['topics', ['withCount' => true]]])` and read `entry.topics.count` instead.

Craft 5 also supports lazy eager-loading via `entry.topics.eagerly().all()`, which is a good fit
when the loop is inside an include you don't control.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/development/eager-loading.html';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/\.(all|one|count|exists|nth)\(\s*\)/',
            $source,
            $structure,
            filter: function(int $offset, array $match, int $line) use ($source, $structure) {
                if (!$structure->isInsideLoopAt($offset)) {
                    return false;
                }

                // `entry.topics.eagerly().all()` is lazy eager-loading: one query for the whole
                // loop, not one per row. It is also what this rule's own remediation recommends
                // for loops you can't reach the feeding query from, so flagging it would mean
                // following the advice can never clear the warning.
                if ($this->isEagerlyLoaded($source, $offset)) {
                    return false;
                }

                // A `craft.` call on the same line is a fresh query, which QueryInLoopRule already
                // reports with better advice. Reporting both would double-count one mistake.
                return !str_contains($source->line($line), 'craft.');
            },
            detail: fn(int $offset, array $match) => sprintf('.%s() called per iteration', $match[1]),
        );
    }

    /**
     * Whether the call at `$offset` is executing a query that was already marked `.eagerly()`.
     *
     * Looks at the method chain the call belongs to rather than the whole line, so a genuine
     * `entry.topics.all()` still gets reported when something else on the same line happens to be
     * eager-loaded.
     */
    private function isEagerlyLoaded(TemplateSource $source, int $offset): bool
    {
        return preg_match('/\.eagerly[^\S\r\n]*\(/', $this->chainBefore($source, $offset)) === 1;
    }

    /**
     * The chain of `.method(…)` calls immediately preceding an offset, e.g. `.image.eagerly()`.
     *
     * Stops at the first thing that isn't part of the chain — whitespace that isn't followed by a
     * dot, an opening bracket, a filter — which is what keeps two expressions on one line apart.
     */
    private function chainBefore(TemplateSource $source, int $offset): string
    {
        $head = substr($source->masked(), 0, $offset);
        $newline = strrpos($head, "\n");
        $prefix = $newline === false ? $head : substr($head, $newline + 1);

        preg_match('/(?:[^\S\r\n]*\.[A-Za-z_]\w*(?:\((?:[^()\r\n]|\([^()\r\n]*\))*\))?)+[^\S\r\n]*$/', $prefix, $match);

        return $match[0] ?? '';
    }
}
