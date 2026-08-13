<?php

namespace justinholtweb\microscope\templates;

/**
 * Where the blocks are in a template.
 *
 * Most of what makes a Twig template slow is not *what* it does but *where* it does it: a single
 * `craft.entries.all()` is fine, the same call inside `{% for %}` is one query per row. So the
 * rules need to answer "is this inside a loop?" and "is this inside `{% cache %}`?".
 *
 * **Everything here is expressed in byte offsets, not line numbers.** That is deliberate and was
 * learned the hard way: a one-line `{% for x in xs %}{{ craft.entries().all() }}{% endfor %}` is
 * entirely inside a loop, but every part of it is on the *same line* as the opening tag — so any
 * line-based containment test says it isn't, and the single most important rule in the plugin
 * silently misses one of the most common ways to write the mistake. Lines are a display concept;
 * position is the real one. {@see TemplateSource::lineAt()} converts back for reporting.
 *
 * This is not a Twig parser and does not try to be. It walks the `{% … %}` tags in order, keeps a
 * stack of open blocks, and records the span each one covers — enough to answer those questions
 * accurately on real-world templates, and cheap enough to run over a few hundred files in one
 * scan. The one thing it does carefully is unwinding: an `{% endfoo %}` pops down to the matching
 * `{% foo %}` rather than blindly popping the top of the stack, so a tag this class doesn't know
 * about can't knock the rest of the file out of alignment.
 */
class TemplateStructure
{
    /**
     * Tags that open a block and are closed by `end<tag>`.
     *
     * Includes Craft's own additions (`cache`, `nav`, `js`, `css`, `script`, `namespace`), because
     * they nest like any other block and an unrecognised one would leave the stack unbalanced.
     */
    private const BLOCK_TAGS = [
        'apply', 'autoescape', 'block', 'cache', 'css', 'embed', 'filter', 'for', 'if',
        'ifchildren', 'js', 'macro', 'namespace', 'nav', 'sandbox', 'script', 'set', 'spaceless',
        'switch', 'tag', 'verbatim', 'with',
    ];

    /**
     * @var array<int, array{tag: string, start: int, end: int}> Every block found, in the order
     *      they opened. `start` is the offset just past the opening tag and `end` the offset of
     *      the closing tag, so the span covers the block's contents and neither of its delimiters.
     */
    private array $blocks = [];

    /**
     * @var array<string, int> How many times each tag appears at all.
     */
    private array $tagCounts = [];

    private function __construct(private readonly TemplateSource $source)
    {
    }

    public static function of(TemplateSource $source): self
    {
        $structure = new self($source);
        $structure->parse();

        return $structure;
    }

    /**
     * How many `{% for %}` blocks enclose this position. 0 means top level.
     */
    public function loopDepthAt(int $offset): int
    {
        return count($this->enclosing($offset, 'for'));
    }

    public function isInsideLoopAt(int $offset): bool
    {
        return $this->loopDepthAt($offset) > 0;
    }

    /**
     * Whether the position sits inside a `{% cache %}` block.
     *
     * A query inside a loop inside a cache tag still costs the full price on the first request,
     * but it is a considered decision rather than an accident, so rules downgrade it instead of
     * staying silent — silence would teach people that wrapping everything in `{% cache %}` makes
     * the warning "correct".
     */
    public function isInsideCacheAt(int $offset): bool
    {
        return $this->enclosing($offset, 'cache') !== [];
    }

    /**
     * Whether the position is inside `{% verbatim %}`, which is documentation rather than code
     * that runs.
     */
    public function isInsideVerbatimAt(int $offset): bool
    {
        return $this->enclosing($offset, 'verbatim') !== [];
    }

    /**
     * The line the innermost enclosing loop opens on, or null.
     *
     * Returns a line rather than an offset because its only use is telling a developer where to
     * look.
     */
    public function enclosingLoopLine(int $offset): ?int
    {
        $loops = $this->enclosing($offset, 'for');

        if ($loops === []) {
            return null;
        }

        // The innermost loop is the one that opened last, and it's the one whose iteration count
        // multiplies the cost.
        $innermost = $loops[count($loops) - 1];

        return $this->source->lineAt($innermost['start']);
    }

    public function countTag(string $tag): int
    {
        return $this->tagCounts[$tag] ?? 0;
    }

    public function hasTag(string $tag): bool
    {
        return $this->countTag($tag) > 0;
    }

    /**
     * @return array<int, array{tag: string, start: int, end: int}>
     */
    public function blocks(?string $tag = null): array
    {
        if ($tag === null) {
            return $this->blocks;
        }

        return array_values(array_filter($this->blocks, fn(array $b) => $b['tag'] === $tag));
    }

    /**
     * Blocks of a given tag containing this position, outermost first.
     *
     * @return array<int, array{tag: string, start: int, end: int}>
     */
    private function enclosing(int $offset, string $tag): array
    {
        return array_values(array_filter(
            $this->blocks,
            fn(array $block) => $block['tag'] === $tag
                && $offset >= $block['start']
                && $offset < $block['end'],
        ));
    }

    private function parse(): void
    {
        preg_match_all(
            '/\{%-?\s*(\w+)(.*?)-?%\}/s',
            $this->source->source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        /** @var array<int, array{tag: string, index: int}> $stack */
        $stack = [];

        foreach ($matches[1] as $i => [$tag, $tagOffset]) {
            $tag = strtolower($tag);
            $rest = trim($matches[2][$i][0]);

            $tagStart = (int)$matches[0][$i][1];
            $tagEnd = $tagStart + strlen($matches[0][$i][0]);

            $this->tagCounts[$tag] = ($this->tagCounts[$tag] ?? 0) + 1;

            if (str_starts_with($tag, 'end')) {
                $this->closeBlock($stack, substr($tag, 3), $tagStart);
                continue;
            }

            if (!in_array($tag, self::BLOCK_TAGS, true)) {
                continue;
            }

            // `{% set x = 1 %}` is a statement; `{% set x %}…{% endset %}` is a block. Only the
            // second one belongs on the stack, and telling them apart is just whether there's an
            // assignment in the tag.
            if ($tag === 'set' && str_contains($rest, '=')) {
                continue;
            }

            // An unclosed block runs to the end of the file — which is what Twig would do with it
            // anyway, and keeps a truncated template from disabling the rules for its whole body.
            $this->blocks[] = [
                'tag' => $tag,
                'start' => $tagEnd,
                'end' => strlen($this->source->source),
            ];

            $stack[] = ['tag' => $tag, 'index' => count($this->blocks) - 1];
        }
    }

    /**
     * Closes the nearest open block with this tag, discarding anything left open inside it.
     *
     * @param array<int, array{tag: string, index: int}> $stack
     */
    private function closeBlock(array &$stack, string $tag, int $offset): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['tag'] !== $tag) {
                continue;
            }

            $this->blocks[$stack[$i]['index']]['end'] = $offset;
            $stack = array_slice($stack, 0, $i);

            return;
        }

        // No matching opener — a stray `{% endif %}`, or a block opened inside an `{% if %}` and
        // closed outside it. Nothing sensible to close, so leave the stack alone.
    }
}
