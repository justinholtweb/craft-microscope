<?php

namespace justinholtweb\microscope\templates;

/**
 * One Twig template, as text.
 *
 * Deliberately just a path and a string. Everything that reads templates in Microscope goes
 * through this, which is what keeps the whole template category testable without a Craft
 * application, a site, or a filesystem.
 */
class TemplateSource
{
    /**
     * @var string[]|null Lazily split lines, 1-indexed via {@see line()}.
     */
    private ?array $lines = null;

    /**
     * @var int[]|null Byte offset at which each line starts.
     */
    private ?array $lineOffsets = null;

    public function __construct(
        public readonly string $path,
        public readonly string $source,
    ) {
    }

    public static function fromFile(string $file, string $path): ?self
    {
        $source = @file_get_contents($file);

        return $source === false ? null : new self($path, $source);
    }

    /**
     * @var string|null Source with comments blanked out.
     */
    private ?string $masked = null;

    /**
     * The source with `{# … #}` comments replaced by spaces.
     *
     * Rules scan this rather than the raw source. Commented-out code is extremely common in
     * templates — often it is the slow version somebody replaced — and reporting it would be both
     * wrong and impossible to act on. Blanking rather than deleting keeps every byte offset
     * identical, so line numbers still line up with the file on disk.
     */
    public function masked(): string
    {
        return $this->masked ??= (string)preg_replace_callback(
            '/\{#.*?#\}/s',
            fn(array $m) => preg_replace('/[^\r\n]/', ' ', $m[0]),
            $this->source,
        );
    }

    /**
     * @return string[] 0-indexed.
     */
    public function lines(): array
    {
        return $this->lines ??= preg_split('/\r\n|\r|\n/', $this->source) ?: [];
    }

    public function lineCount(): int
    {
        return count($this->lines());
    }

    /**
     * A single line, 1-indexed to match everything a developer sees in an editor.
     */
    public function line(int $number): string
    {
        return $this->lines()[$number - 1] ?? '';
    }

    /**
     * A trimmed, length-capped version of a line, for showing in a report.
     */
    public function snippet(int $number, int $maxLength = 160): string
    {
        $line = trim($this->line($number));

        if (mb_strlen($line) <= $maxLength) {
            return $line;
        }

        return mb_substr($line, 0, $maxLength - 1) . '…';
    }

    /**
     * The 1-indexed line a byte offset falls on.
     *
     * Uses a binary search over precomputed line starts: a rule that reports fifty matches in a
     * long template would otherwise re-scan the source once per match.
     */
    public function lineAt(int $offset): int
    {
        $offsets = $this->lineOffsets ??= $this->computeLineOffsets();

        $low = 0;
        $high = count($offsets) - 1;

        while ($low < $high) {
            $mid = (int)ceil(($low + $high) / 2);

            if ($offsets[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low + 1;
    }

    /**
     * @return int[]
     */
    private function computeLineOffsets(): array
    {
        $offsets = [0];
        $length = strlen($this->source);

        for ($i = 0; $i < $length; $i++) {
            if ($this->source[$i] === "\n") {
                $offsets[] = $i + 1;
            }
        }

        return $offsets;
    }
}
