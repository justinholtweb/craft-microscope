<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;
use PHPUnit\Framework\TestCase;

/**
 * Almost every template rule turns on one question — "is this inside a loop?" — so the block
 * tracking has to be right on templates messier than the examples.
 *
 * Positions are located by their text rather than by line number, which is both more readable and
 * the thing being tested: containment is a *position* question, and treating it as a line question
 * is exactly the bug these tests exist to prevent.
 */
final class TemplateStructureTest extends TestCase
{
    private string $twig = '';

    private function structure(string $twig): TemplateStructure
    {
        $this->twig = $twig;

        return TemplateStructure::of(new TemplateSource('test.twig', $twig));
    }

    /**
     * The offset of a distinctive piece of the template under test.
     */
    private function at(string $needle): int
    {
        $offset = strpos($this->twig, $needle);

        self::assertNotFalse($offset, sprintf('“%s” is not in the template under test.', $needle));

        return $offset;
    }

    public function testTracksASimpleLoop(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for entry in entries %}
              {{ entry.title }}
            {% endfor %}
            {{ 'outside' }}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
        self::assertFalse($structure->isInsideLoopAt($this->at("{{ 'outside' }}")));
    }

    /**
     * The case that a line-based implementation gets wrong: everything here is on one line,
     * including the opening tag, but the query is unambiguously inside the loop.
     */
    public function testTracksASingleLineLoop(): void
    {
        $structure = $this->structure(
            "{% for e in es %}{{ craft.entries().all() }}{% endfor %}{{ after }}",
        );

        self::assertTrue($structure->isInsideLoopAt($this->at('craft.entries')));
        self::assertFalse($structure->isInsideLoopAt($this->at('{{ after }}')));
    }

    public function testCountsNestedLoopDepth(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for a in as %}
              {{ outer }}
              {% for b in bs %}
                {{ inner }}
              {% endfor %}
            {% endfor %}
            {{ after }}
            TWIG);

        self::assertSame(1, $structure->loopDepthAt($this->at('{{ outer }}')));
        self::assertSame(2, $structure->loopDepthAt($this->at('{{ inner }}')));
        self::assertSame(0, $structure->loopDepthAt($this->at('{{ after }}')));
    }

    public function testIfBlocksInsideLoopsDoNotConfuseTheStack(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for entry in entries %}
              {% if entry.enabled %}
                {{ entry.title }}
              {% endif %}
            {% endfor %}
            {{ after }}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
        self::assertFalse($structure->isInsideLoopAt($this->at('{{ after }}')));
    }

    /**
     * `{% set x = 1 %}` is a statement; `{% set x %}…{% endset %}` is a block. Treating the first
     * as a block would leave everything after it one level too deep.
     */
    public function testInlineSetIsNotTreatedAsABlock(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% set count = 5 %}
            {% for entry in entries %}
              {{ entry.title }}
            {% endfor %}
            {{ after }}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
        self::assertFalse($structure->isInsideLoopAt($this->at('{{ after }}')));
    }

    public function testBlockSetIsTrackedAsABlock(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% set intro %}
              {{ inside }}
            {% endset %}
            {{ after }}
            TWIG);

        self::assertCount(1, $structure->blocks('set'));
        self::assertFalse($structure->isInsideLoopAt($this->at('{{ after }}')));
    }

    public function testRecognisesCacheBlocks(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% cache for 1 hour %}
              {{ cached }}
            {% endcache %}
            {{ after }}
            TWIG);

        self::assertTrue($structure->isInsideCacheAt($this->at('{{ cached }}')));
        self::assertFalse($structure->isInsideCacheAt($this->at('{{ after }}')));
        self::assertTrue($structure->hasTag('cache'));
    }

    public function testRecognisesVerbatimBlocks(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% verbatim %}
              {{ documented }}
            {% endverbatim %}
            {{ after }}
            TWIG);

        self::assertTrue($structure->isInsideVerbatimAt($this->at('{{ documented }}')));
        self::assertFalse($structure->isInsideVerbatimAt($this->at('{{ after }}')));
    }

    /**
     * A stray `{% endif %}` must not unwind blocks that are legitimately open — otherwise one
     * malformed template silently disables the analysis for the rest of the file.
     */
    public function testUnmatchedEndTagDoesNotUnwindTheStack(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for entry in entries %}
              {% endif %}
              {{ entry.title }}
            {% endfor %}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
    }

    /**
     * A block nobody closed runs to the end of the file, which is what Twig does with it too.
     */
    public function testUnclosedBlockExtendsToTheEndOfTheFile(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for entry in entries %}
              {{ entry.title }}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
    }

    public function testHandlesWhitespaceControlSyntax(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {%- for entry in entries -%}
              {{ entry.title }}
            {%- endfor -%}
            {{ after }}
            TWIG);

        self::assertTrue($structure->isInsideLoopAt($this->at('{{ entry.title }}')));
        self::assertFalse($structure->isInsideLoopAt($this->at('{{ after }}')));
    }

    public function testReportsTheEnclosingLoopLine(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for a in as %}
              {% for b in bs %}
                {{ inner }}
              {% endfor %}
              {{ outer }}
            {% endfor %}
            {{ after }}
            TWIG);

        // The innermost loop wins — it's the one whose iteration count multiplies the cost.
        self::assertSame(2, $structure->enclosingLoopLine($this->at('{{ inner }}')));
        self::assertSame(1, $structure->enclosingLoopLine($this->at('{{ outer }}')));
        self::assertNull($structure->enclosingLoopLine($this->at('{{ after }}')));
    }

    public function testCountsTags(): void
    {
        $structure = $this->structure(<<<'TWIG'
            {% for a in as %}{% endfor %}
            {% for b in bs %}{% endfor %}
            {% if c %}{% endif %}
            TWIG);

        self::assertSame(2, $structure->countTag('for'));
        self::assertSame(1, $structure->countTag('if'));
        self::assertSame(0, $structure->countTag('cache'));
        self::assertFalse($structure->hasTag('cache'));
    }
}
