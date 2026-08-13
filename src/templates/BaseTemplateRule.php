<?php

namespace justinholtweb\microscope\templates;

use justinholtweb\microscope\models\Finding;

/**
 * Shared plumbing for template rules: matching a pattern and turning matches into issues.
 */
abstract class BaseTemplateRule implements TemplateRuleInterface
{
    public function severity(): string
    {
        return Finding::SEVERITY_WARNING;
    }

    public function docsUrl(): ?string
    {
        return null;
    }

    /**
     * Runs a regex over the comment-stripped source and yields one issue per match, skipping
     * anything inside `{% verbatim %}`.
     *
     * Callbacks receive the match's byte offset alongside its line, because block containment is
     * an offset question — see {@see TemplateStructure} — while reporting is a line one.
     *
     * @param callable(int $offset, array<int, string> $match, int $line): bool|null $filter
     *        Return false to discard a match.
     * @param callable(int $offset, array<int, string> $match, int $line): ?string|null $detail
     * @return TemplateIssue[]
     */
    protected function matchAll(
        string $pattern,
        TemplateSource $source,
        TemplateStructure $structure,
        ?callable $filter = null,
        ?callable $detail = null,
    ): array {
        if (!preg_match_all($pattern, $source->masked(), $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $issues = [];
        $seenLines = [];

        foreach ($matches as $match) {
            $offset = (int)$match[0][1];
            $line = $source->lineAt($offset);

            if ($structure->isInsideVerbatimAt($offset)) {
                continue;
            }

            $flat = array_map(fn(array $group) => $group[0], $match);

            if ($filter !== null && $filter($offset, $flat, $line) === false) {
                continue;
            }

            // Two matches on one line are one problem to a reader.
            if (isset($seenLines[$line])) {
                continue;
            }

            $seenLines[$line] = true;

            $issues[] = new TemplateIssue(
                rule: $this->handle(),
                templatePath: $source->path,
                line: $line,
                snippet: $source->snippet($line),
                detail: $detail !== null ? $detail($offset, $flat, $line) : null,
                cached: $structure->isInsideCacheAt($offset),
            );
        }

        return $issues;
    }
}
