<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\templates\TemplateAnalyzer;
use justinholtweb\microscope\templates\TemplateIssue;
use justinholtweb\microscope\templates\TemplateSource;
use PHPUnit\Framework\TestCase;

/**
 * The template rules.
 *
 * These are static analysis, so the standard they're held to here is **no false positives on
 * ordinary code**. A rule that flags correct templates trains people to ignore the report, which
 * is worse than a rule that misses things — so roughly half of these tests assert that something
 * is *not* reported.
 */
final class TemplateAnalyzerTest extends TestCase
{
    private TemplateAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new TemplateAnalyzer();
    }

    /**
     * @return TemplateIssue[]
     */
    private function analyze(string $twig): array
    {
        return $this->analyzer->analyze(new TemplateSource('test.twig', $twig));
    }

    /**
     * @param TemplateIssue[] $issues
     * @return TemplateIssue[]
     */
    private function only(array $issues, string $rule): array
    {
        return array_values(array_filter($issues, fn(TemplateIssue $i) => $i->rule === $rule));
    }

    // -----------------------------------------------------------------
    // queryInLoop
    // -----------------------------------------------------------------

    public function testFlagsAnElementQueryInsideALoop(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {% set related = craft.entries().section('news').limit(3).all() %}
            {% endfor %}
            TWIG), 'queryInLoop');

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->line);
        self::assertStringContainsString('craft.entries', (string)$issues[0]->detail);
    }

    public function testDoesNotFlagAQueryOutsideALoop(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set entries = craft.entries().section('news').limit(10).all() %}
            {% for entry in entries %}
              {{ entry.title }}
            {% endfor %}
            TWIG), 'queryInLoop');

        self::assertSame([], $issues);
    }

    public function testRecordsLoopDepth(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for a in as %}
              {% for b in bs %}
                {{ craft.assets().id(b.id).limit(1).all()|length }}
              {% endfor %}
            {% endfor %}
            TWIG), 'queryInLoop');

        self::assertCount(1, $issues);
        self::assertStringContainsString('2 loops deep', (string)$issues[0]->detail);
    }

    /**
     * Commented-out code is extremely common in templates, and it's often the *slow* version
     * somebody replaced. Reporting it would be both wrong and impossible to act on.
     */
    public function testIgnoresCommentedOutCode(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {# {% set x = craft.entries().all() %} #}
              {{ entry.title }}
            {% endfor %}
            TWIG), 'queryInLoop');

        self::assertSame([], $issues);
    }

    public function testIgnoresVerbatimBlocks(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for example in examples %}
              {% verbatim %}
                {% set x = craft.entries().all() %}
              {% endverbatim %}
            {% endfor %}
            TWIG), 'queryInLoop');

        self::assertSame([], $issues);
    }

    public function testMarksMatchesInsideCacheBlocks(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% cache for 1 hour %}
              {% for entry in entries %}
                {% set x = craft.entries().limit(1).all() %}
              {% endfor %}
            {% endcache %}
            TWIG), 'queryInLoop');

        self::assertCount(1, $issues);
        self::assertTrue($issues[0]->cached);
    }

    // -----------------------------------------------------------------
    // relationInLoop
    // -----------------------------------------------------------------

    public function testFlagsARelationExecutedInsideALoop(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {% for tag in entry.topics.all() %}{{ tag.title }}{% endfor %}
            {% endfor %}
            TWIG), 'relationInLoop');

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]->line);
    }

    public function testDoesNotDoubleReportACraftQueryAsARelation(): void
    {
        // QueryInLoopRule already reports this, with better advice. Reporting it twice would make
        // one mistake look like two.
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {% set x = craft.entries().limit(1).all() %}
            {% endfor %}
            TWIG), 'relationInLoop');

        self::assertSame([], $issues);
    }

    public function testDoesNotFlagAnEagerLoadedRelation(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in craft.entries().limit(10).with(['topics']).all() %}
              {% for tag in entry.topics %}{{ tag.title }}{% endfor %}
            {% endfor %}
            TWIG), 'relationInLoop');

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // countViaAll
    // -----------------------------------------------------------------

    public function testFlagsCountingViaAll(): void
    {
        $issues = $this->only(
            $this->analyze("{{ craft.entries().section('news').all()|length }}"),
            'countViaAll',
        );

        self::assertCount(1, $issues);
    }

    public function testDoesNotFlagLengthOnAVariable(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set entries = craft.entries().limit(10).all() %}
            {{ entries|length }}
            TWIG), 'countViaAll');

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // unboundedQuery
    // -----------------------------------------------------------------

    public function testFlagsAQueryWithNoLimit(): void
    {
        $issues = $this->only(
            $this->analyze("{% set posts = craft.entries().section('news').all() %}"),
            'unboundedQuery',
        );

        self::assertCount(1, $issues);
    }

    public function testDoesNotFlagALimitedQuery(): void
    {
        $issues = $this->only(
            $this->analyze("{% set posts = craft.entries().section('news').limit(12).all() %}"),
            'unboundedQuery',
        );

        self::assertSame([], $issues);
    }

    /**
     * Queries chain across lines far more often than not, so the lookahead has to survive it.
     */
    public function testHandlesMultiLineQueryChains(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set posts = craft.entries()
              .section('news')
              .orderBy('postDate desc')
              .all() %}
            TWIG), 'unboundedQuery');

        self::assertCount(1, $issues);

        $limited = $this->only($this->analyze(<<<'TWIG'
            {% set posts = craft.entries()
              .section('news')
              .limit(12)
              .all() %}
            TWIG), 'unboundedQuery');

        self::assertSame([], $limited);
    }

    public function testDoesNotFlagAQueryFetchedById(): void
    {
        // An ID lookup is bounded by definition.
        $issues = $this->only(
            $this->analyze("{% set image = craft.assets().id(entry.imageId).all() %}"),
            'unboundedQuery',
        );

        self::assertSame([], $issues);
    }

    public function testDoesNotFlagPaginatedQueries(): void
    {
        $issues = $this->only(
            $this->analyze("{% paginate craft.entries().section('news') as pageInfo, posts %}"),
            'unboundedQuery',
        );

        self::assertSame([], $issues);
    }

    public function testDoesNotFlagOneOrCount(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set featured = craft.entries().section('news').one() %}
            {% set total = craft.entries().section('news').count() %}
            TWIG), 'unboundedQuery');

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // includeInLoop
    // -----------------------------------------------------------------

    public function testFlagsIncludeInLoopWithoutOnly(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {% include '_partials/card' with { entry: entry } %}
            {% endfor %}
            TWIG), 'includeInLoop');

        self::assertCount(1, $issues);
    }

    public function testDoesNotFlagIncludeWithOnly(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% for entry in entries %}
              {% include '_partials/card' with { entry: entry } only %}
            {% endfor %}
            TWIG), 'includeInLoop');

        self::assertSame([], $issues);
    }

    public function testDoesNotFlagIncludeOutsideALoop(): void
    {
        $issues = $this->only(
            $this->analyze("{% include '_partials/header' %}"),
            'includeInLoop',
        );

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // imageDimensions
    // -----------------------------------------------------------------

    public function testFlagsImagesWithoutDimensions(): void
    {
        $issues = $this->only(
            $this->analyze('<img src="{{ image.url }}" alt="">'),
            'imageDimensions',
        );

        self::assertCount(1, $issues);
        self::assertStringContainsString('width/height', (string)$issues[0]->detail);
        self::assertStringContainsString('loading', (string)$issues[0]->detail);
    }

    public function testDoesNotFlagACompleteImageTag(): void
    {
        $issues = $this->only(
            $this->analyze('<img src="{{ image.url }}" width="800" height="600" alt="" loading="lazy">'),
            'imageDimensions',
        );

        self::assertSame([], $issues);
    }

    public function testAcceptsDimensionsRenderedByTwig(): void
    {
        $issues = $this->only($this->analyze(
            '<img src="{{ image.url }}" width="{{ image.width }}" height="{{ image.height }}" loading="lazy" alt="">',
        ), 'imageDimensions');

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // missingCacheTag
    // -----------------------------------------------------------------

    public function testFlagsQueryHeavyTemplatesWithNoCaching(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set a = craft.entries().limit(1).all() %}
            {% set b = craft.entries().limit(1).all() %}
            {% set c = craft.assets().limit(1).all() %}
            {% set d = craft.categories().limit(1).all() %}
            TWIG), 'missingCacheTag');

        self::assertCount(1, $issues);
        self::assertStringContainsString('4 element queries', (string)$issues[0]->detail);
    }

    public function testDoesNotFlagATemplateThatCachesSomething(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% cache %}
              {% set a = craft.entries().limit(1).all() %}
              {% set b = craft.entries().limit(1).all() %}
              {% set c = craft.assets().limit(1).all() %}
              {% set d = craft.categories().limit(1).all() %}
            {% endcache %}
            TWIG), 'missingCacheTag');

        self::assertSame([], $issues);
    }

    public function testDoesNotFlagATemplateWithFewQueries(): void
    {
        $issues = $this->only($this->analyze(<<<'TWIG'
            {% set a = craft.entries().limit(1).all() %}
            {% set b = craft.entries().limit(1).all() %}
            TWIG), 'missingCacheTag');

        self::assertSame([], $issues);
    }

    // -----------------------------------------------------------------
    // analyzer plumbing
    // -----------------------------------------------------------------

    public function testAnalyzeAllGroupsIssuesByRule(): void
    {
        $sources = [
            new TemplateSource('a.twig', "{% for e in es %}{{ craft.entries().limit(1).all()|length }}{% endfor %}"),
            new TemplateSource('b.twig', '<img src="x.jpg">'),
        ];

        $grouped = $this->analyzer->analyzeAll($sources);

        self::assertArrayHasKey('queryInLoop', $grouped);
        self::assertArrayHasKey('imageDimensions', $grouped);
        self::assertSame('a.twig', $grouped['queryInLoop'][0]->templatePath);
        self::assertSame('b.twig', $grouped['imageDimensions'][0]->templatePath);
    }

    public function testOnlyRestrictsTheRuleSet(): void
    {
        $restricted = $this->analyzer->only(['imageDimensions']);

        self::assertCount(1, $restricted->rules());
        self::assertSame('imageDimensions', $restricted->rules()[0]->handle());
    }

    public function testACleanTemplateProducesNoIssues(): void
    {
        $issues = $this->analyze(<<<'TWIG'
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

        self::assertSame([], $issues, 'A well-written template should produce no findings at all.');
    }
}
