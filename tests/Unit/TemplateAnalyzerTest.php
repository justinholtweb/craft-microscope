<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateAnalyzer;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateIssue;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateSource;

/**
 * The template rules.
 *
 * These are static analysis, so the standard they're held to here is **no false positives on
 * ordinary code**. A rule that flags correct templates trains people to ignore the report, which
 * is worse than a rule that misses things — so roughly half of these tests assert that something
 * is *not* reported.
 */

/**
 * @return TemplateIssue[]
 */
function analyzerAnalyze(string $twig): array
{
    return (new TemplateAnalyzer)->analyze(new TemplateSource('test.twig', $twig));
}

/**
 * @param  TemplateIssue[]  $issues
 * @return TemplateIssue[]
 */
function analyzerOnly(array $issues, string $rule): array
{
    return array_values(array_filter($issues, fn (TemplateIssue $i) => $i->rule === $rule));
}

test('flags an element query inside a loop', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% set related = craft.entries().section('news').limit(3).all() %}
        {% endfor %}
        TWIG), 'queryInLoop');

    $this->assertCount(1, $issues);
    $this->assertSame(2, $issues[0]->line);
    $this->assertStringContainsString('craft.entries', (string) $issues[0]->detail);
});

test('does not flag a query outside a loop', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set entries = craft.entries().section('news').limit(10).all() %}
        {% for entry in entries %}
          {{ entry.title }}
        {% endfor %}
        TWIG), 'queryInLoop');

    $this->assertSame([], $issues);
});

test('records loop depth', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for a in as %}
          {% for b in bs %}
            {{ craft.assets().id(b.id).limit(1).all()|length }}
          {% endfor %}
        {% endfor %}
        TWIG), 'queryInLoop');

    $this->assertCount(1, $issues);
    $this->assertStringContainsString('2 loops deep', (string) $issues[0]->detail);
});

/**
 * Commented-out code is extremely common in templates, and it's often the *slow* version
 * somebody replaced. Reporting it would be both wrong and impossible to act on.
 */
test('ignores commented out code', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {# {% set x = craft.entries().all() %} #}
          {{ entry.title }}
        {% endfor %}
        TWIG), 'queryInLoop');

    $this->assertSame([], $issues);
});

test('ignores verbatim blocks', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for example in examples %}
          {% verbatim %}
            {% set x = craft.entries().all() %}
          {% endverbatim %}
        {% endfor %}
        TWIG), 'queryInLoop');

    $this->assertSame([], $issues);
});

test('marks matches inside cache blocks', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% cache for 1 hour %}
          {% for entry in entries %}
            {% set x = craft.entries().limit(1).all() %}
          {% endfor %}
        {% endcache %}
        TWIG), 'queryInLoop');

    $this->assertCount(1, $issues);
    $this->assertTrue($issues[0]->cached);
});

test('flags a relation executed inside a loop', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% for tag in entry.topics.all() %}{{ tag.title }}{% endfor %}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertCount(1, $issues);
    $this->assertSame(2, $issues[0]->line);
});

test('does not double report a craft query as a relation', function () {
    // QueryInLoopRule already reports this, with better advice. Reporting it twice would make
    // one mistake look like two.
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% set x = craft.entries().limit(1).all() %}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertSame([], $issues);
});

test('does not flag an eager loaded relation', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in craft.entries().limit(10).with(['topics']).all() %}
          {% for tag in entry.topics %}{{ tag.title }}{% endfor %}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertSame([], $issues);
});

/**
 * Lazy eager-loading runs one query for the whole loop. It is also what this rule's own
 * remediation recommends, so flagging it would mean following the advice can never clear the
 * warning. See #2.
 */
test('does not flag a lazily eager loaded relation', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for block in entry.gallery.all() %}
          {{ block.image.eagerly().one().url }}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertSame([], $issues);
});

test('does not flag lazy eager loading with an alias', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% for tag in entry.topics.eagerly('entryTopics').all() %}{{ tag.title }}{% endfor %}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertSame([], $issues);
});

test('does not flag lazy eager loading followed by other query methods', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% for tag in entry.topics.eagerly().limit(3).all() %}{{ tag.title }}{% endfor %}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertSame([], $issues);
});

/**
 * `.eagerly()` earlier in the line belongs to a different expression, so the bare relation
 * next to it is still one query per row.
 */
test('still flags a bare relation beside an eager loaded one', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {{ entry.topics.eagerly().count() }} {{ entry.authors.count() }}
        {% endfor %}
        TWIG), 'relationInLoop');

    $this->assertCount(1, $issues);
    $this->assertSame(2, $issues[0]->line);
    $this->assertStringContainsString('.count()', (string) $issues[0]->detail);
});

test('flags counting via all', function () {
    $issues = analyzerOnly(
        analyzerAnalyze("{{ craft.entries().section('news').all()|length }}"),
        'countViaAll',
    );

    $this->assertCount(1, $issues);
});

test('does not flag length on a variable', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set entries = craft.entries().limit(10).all() %}
        {{ entries|length }}
        TWIG), 'countViaAll');

    $this->assertSame([], $issues);
});

test('flags a query with no limit', function () {
    $issues = analyzerOnly(
        analyzerAnalyze("{% set posts = craft.entries().section('news').all() %}"),
        'unboundedQuery',
    );

    $this->assertCount(1, $issues);
});

test('does not flag a limited query', function () {
    $issues = analyzerOnly(
        analyzerAnalyze("{% set posts = craft.entries().section('news').limit(12).all() %}"),
        'unboundedQuery',
    );

    $this->assertSame([], $issues);
});

/**
 * Queries chain across lines far more often than not, so the lookahead has to survive it.
 */
test('handles multi line query chains', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set posts = craft.entries()
          .section('news')
          .orderBy('postDate desc')
          .all() %}
        TWIG), 'unboundedQuery');

    $this->assertCount(1, $issues);

    $limited = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set posts = craft.entries()
          .section('news')
          .limit(12)
          .all() %}
        TWIG), 'unboundedQuery');

    $this->assertSame([], $limited);
});

test('does not flag a query fetched by id', function () {
    // An ID lookup is bounded by definition.
    $issues = analyzerOnly(
        analyzerAnalyze('{% set image = craft.assets().id(entry.imageId).all() %}'),
        'unboundedQuery',
    );

    $this->assertSame([], $issues);
});

test('does not flag paginated queries', function () {
    $issues = analyzerOnly(
        analyzerAnalyze("{% paginate craft.entries().section('news') as pageInfo, posts %}"),
        'unboundedQuery',
    );

    $this->assertSame([], $issues);
});

test('does not flag one or count', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set featured = craft.entries().section('news').one() %}
        {% set total = craft.entries().section('news').count() %}
        TWIG), 'unboundedQuery');

    $this->assertSame([], $issues);
});

test('flags include in loop without only', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% include '_partials/card' with { entry: entry } %}
        {% endfor %}
        TWIG), 'includeInLoop');

    $this->assertCount(1, $issues);
});

test('does not flag include with only', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% for entry in entries %}
          {% include '_partials/card' with { entry: entry } only %}
        {% endfor %}
        TWIG), 'includeInLoop');

    $this->assertSame([], $issues);
});

test('does not flag include outside a loop', function () {
    $issues = analyzerOnly(
        analyzerAnalyze("{% include '_partials/header' %}"),
        'includeInLoop',
    );

    $this->assertSame([], $issues);
});

test('flags images without dimensions', function () {
    $issues = analyzerOnly(
        analyzerAnalyze('<img src="{{ image.url }}" alt="">'),
        'imageDimensions',
    );

    $this->assertCount(1, $issues);
    $this->assertStringContainsString('width/height', (string) $issues[0]->detail);
    $this->assertStringContainsString('loading', (string) $issues[0]->detail);
});

test('does not flag a complete image tag', function () {
    $issues = analyzerOnly(
        analyzerAnalyze('<img src="{{ image.url }}" width="800" height="600" alt="" loading="lazy">'),
        'imageDimensions',
    );

    $this->assertSame([], $issues);
});

test('accepts dimensions rendered by twig', function () {
    $issues = analyzerOnly(analyzerAnalyze(
        '<img src="{{ image.url }}" width="{{ image.width }}" height="{{ image.height }}" loading="lazy" alt="">',
    ), 'imageDimensions');

    $this->assertSame([], $issues);
});

test('flags query heavy templates with no caching', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set a = craft.entries().limit(1).all() %}
        {% set b = craft.entries().limit(1).all() %}
        {% set c = craft.assets().limit(1).all() %}
        {% set d = craft.categories().limit(1).all() %}
        TWIG), 'missingCacheTag');

    $this->assertCount(1, $issues);
    $this->assertStringContainsString('4 element queries', (string) $issues[0]->detail);
});

test('does not flag a template that caches something', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% cache %}
          {% set a = craft.entries().limit(1).all() %}
          {% set b = craft.entries().limit(1).all() %}
          {% set c = craft.assets().limit(1).all() %}
          {% set d = craft.categories().limit(1).all() %}
        {% endcache %}
        TWIG), 'missingCacheTag');

    $this->assertSame([], $issues);
});

test('does not flag a template with few queries', function () {
    $issues = analyzerOnly(analyzerAnalyze(<<<'TWIG'
        {% set a = craft.entries().limit(1).all() %}
        {% set b = craft.entries().limit(1).all() %}
        TWIG), 'missingCacheTag');

    $this->assertSame([], $issues);
});

test('analyze all groups issues by rule', function () {
    $sources = [
        new TemplateSource('a.twig', '{% for e in es %}{{ craft.entries().limit(1).all()|length }}{% endfor %}'),
        new TemplateSource('b.twig', '<img src="x.jpg">'),
    ];

    $grouped = (new TemplateAnalyzer)->analyzeAll($sources);

    $this->assertArrayHasKey('queryInLoop', $grouped);
    $this->assertArrayHasKey('imageDimensions', $grouped);
    $this->assertSame('a.twig', $grouped['queryInLoop'][0]->templatePath);
    $this->assertSame('b.twig', $grouped['imageDimensions'][0]->templatePath);
});

test('only restricts the rule set', function () {
    $restricted = (new TemplateAnalyzer)->only(['imageDimensions']);

    $this->assertCount(1, $restricted->rules());
    $this->assertSame('imageDimensions', $restricted->rules()[0]->handle());
});

test('a clean template produces no issues', function () {
    $issues = analyzerAnalyze(<<<'TWIG'
        {% extends '_layout' %}

        {% block content %}
          {% cache for 1 hour %}
            {% set posts = craft.entries()
              .section('news')
              .limit(12)
              .with(['heroImage'])
              .all() %}

            {% for post in posts %}
              {% include '_partials/card' with { post: post } only %}
            {% endfor %}

            <p>{{ craft.entries().section('news').count() }} {{ 'posts'|t }}</p>
          {% endcache %}
        {% endblock %}
        TWIG);

    $this->assertSame([], $issues, 'A well-written template should produce no findings at all.');
});
