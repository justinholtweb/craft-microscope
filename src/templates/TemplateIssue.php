<?php

namespace justinholtweb\microscope\templates;

/**
 * One place in one template where a rule matched.
 *
 * Issues are the raw output of template analysis. They get rolled up into a single
 * {@see \justinholtweb\microscope\models\Finding} per rule, with the issues as its evidence — a
 * report that lists "query inside a loop" forty times is unreadable, whereas one that says it
 * happens in seven templates and lists them is actionable.
 */
class TemplateIssue
{
    public function __construct(
        public readonly string $rule,
        public readonly string $templatePath,
        public readonly int $line,
        public readonly string $snippet,
        /**
         * @var string|null Anything rule-specific worth carrying, e.g. the loop depth this was
         *                  found at.
         */
        public readonly ?string $detail = null,
        /**
         * @var bool Whether the match sits inside a `{% cache %}` block, which softens it.
         */
        public readonly bool $cached = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template' => $this->templatePath,
            'line' => $this->line,
            'snippet' => $this->snippet,
            'detail' => $this->detail,
            'cached' => $this->cached,
        ];
    }
}
