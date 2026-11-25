<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateSource;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateStructure;
use PHPUnit\Framework\Assert;

/**
 * Almost every template rule turns on one question — "is this inside a loop?" — so the block
 * tracking has to be right on templates messier than the examples.
 *
 * Positions are located by their text rather than by line number, which is both more readable and
 * the thing being tested: containment is a *position* question, and treating it as a line question
 * is exactly the bug these tests exist to prevent.
 */

/**
 * The template the current test is looking at, remembered so {@see structureAt()} can find offsets in it.
 */
function structureTemplate(?string $twig = null): string
{
    static $current = '';

    if ($twig !== null) {
        $current = $twig;
    }

    return $current;
}

function structureStructure(string $twig): TemplateStructure
{
    structureTemplate($twig);

    return TemplateStructure::of(new TemplateSource('test.twig', $twig));
}

/**
 * The offset of a distinctive piece of the template under test.
 */
function structureAt(string $needle): int
{
    $offset = strpos(structureTemplate(), $needle);

    Assert::assertNotFalse($offset, sprintf('“%s” is not in the template under test.', $needle));

    return $offset;
}

test('tracks a simple loop', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for entry in entries %}
          {{ entry.title }}
        {% endfor %}
        {{ 'outside' }}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
    $this->assertFalse($structure->isInsideLoopAt(structureAt("{{ 'outside' }}")));
});

/**
 * The case that a line-based implementation gets wrong: everything here is on one line,
 * including the opening tag, but the query is unambiguously inside the loop.
 */
test('tracks a single line loop', function () {
    $structure = structureStructure(
        '{% for e in es %}{{ craft.entries().all() }}{% endfor %}{{ after }}',
    );

    $this->assertTrue($structure->isInsideLoopAt(structureAt('craft.entries')));
    $this->assertFalse($structure->isInsideLoopAt(structureAt('{{ after }}')));
});

test('counts nested loop depth', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for a in as %}
          {{ outer }}
          {% for b in bs %}
            {{ inner }}
          {% endfor %}
        {% endfor %}
        {{ after }}
        TWIG);

    $this->assertSame(1, $structure->loopDepthAt(structureAt('{{ outer }}')));
    $this->assertSame(2, $structure->loopDepthAt(structureAt('{{ inner }}')));
    $this->assertSame(0, $structure->loopDepthAt(structureAt('{{ after }}')));
});

test('if blocks inside loops do not confuse the stack', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for entry in entries %}
          {% if entry.enabled %}
            {{ entry.title }}
          {% endif %}
        {% endfor %}
        {{ after }}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
    $this->assertFalse($structure->isInsideLoopAt(structureAt('{{ after }}')));
});

/**
 * `{% set x = 1 %}` is a statement; `{% set x %}…{% endset %}` is a block. Treating the first
 * as a block would leave everything after it one level too deep.
 */
test('inline set is not treated as a block', function () {
    $structure = structureStructure(<<<'TWIG'
        {% set count = 5 %}
        {% for entry in entries %}
          {{ entry.title }}
        {% endfor %}
        {{ after }}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
    $this->assertFalse($structure->isInsideLoopAt(structureAt('{{ after }}')));
});

test('block set is tracked as a block', function () {
    $structure = structureStructure(<<<'TWIG'
        {% set intro %}
          {{ inside }}
        {% endset %}
        {{ after }}
        TWIG);

    $this->assertCount(1, $structure->blocks('set'));
    $this->assertFalse($structure->isInsideLoopAt(structureAt('{{ after }}')));
});

test('recognises cache blocks', function () {
    $structure = structureStructure(<<<'TWIG'
        {% cache for 1 hour %}
          {{ cached }}
        {% endcache %}
        {{ after }}
        TWIG);

    $this->assertTrue($structure->isInsideCacheAt(structureAt('{{ cached }}')));
    $this->assertFalse($structure->isInsideCacheAt(structureAt('{{ after }}')));
    $this->assertTrue($structure->hasTag('cache'));
});

test('recognises verbatim blocks', function () {
    $structure = structureStructure(<<<'TWIG'
        {% verbatim %}
          {{ documented }}
        {% endverbatim %}
        {{ after }}
        TWIG);

    $this->assertTrue($structure->isInsideVerbatimAt(structureAt('{{ documented }}')));
    $this->assertFalse($structure->isInsideVerbatimAt(structureAt('{{ after }}')));
});

/**
 * A stray `{% endif %}` must not unwind blocks that are legitimately open — otherwise one
 * malformed template silently disables the analysis for the rest of the file.
 */
test('unmatched end tag does not unwind the stack', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for entry in entries %}
          {% endif %}
          {{ entry.title }}
        {% endfor %}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
});

/**
 * A block nobody closed runs to the end of the file, which is what Twig does with it too.
 */
test('unclosed block extends to the end of the file', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for entry in entries %}
          {{ entry.title }}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
});

test('handles whitespace control syntax', function () {
    $structure = structureStructure(<<<'TWIG'
        {%- for entry in entries -%}
          {{ entry.title }}
        {%- endfor -%}
        {{ after }}
        TWIG);

    $this->assertTrue($structure->isInsideLoopAt(structureAt('{{ entry.title }}')));
    $this->assertFalse($structure->isInsideLoopAt(structureAt('{{ after }}')));
});

test('reports the enclosing loop line', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for a in as %}
          {% for b in bs %}
            {{ inner }}
          {% endfor %}
          {{ outer }}
        {% endfor %}
        {{ after }}
        TWIG);

    // The innermost loop wins — it's the one whose iteration count multiplies the cost.
    $this->assertSame(2, $structure->enclosingLoopLine(structureAt('{{ inner }}')));
    $this->assertSame(1, $structure->enclosingLoopLine(structureAt('{{ outer }}')));
    $this->assertNull($structure->enclosingLoopLine(structureAt('{{ after }}')));
});

test('counts tags', function () {
    $structure = structureStructure(<<<'TWIG'
        {% for a in as %}{% endfor %}
        {% for b in bs %}{% endfor %}
        {% if c %}{% endif %}
        TWIG);

    $this->assertSame(2, $structure->countTag('for'));
    $this->assertSame(1, $structure->countTag('if'));
    $this->assertSame(0, $structure->countTag('cache'));
    $this->assertFalse($structure->hasTag('cache'));
});
