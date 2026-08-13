<?php

namespace justinholtweb\microscope\templates;

/**
 * A single thing to look for in a Twig template.
 *
 * A rule is responsible for both halves of a finding: spotting the pattern, and explaining it.
 * Keeping the explanation on the rule rather than in a lookup table means a new rule cannot be
 * added without saying what it costs and how to fix it, which is the only reason the template
 * category is worth reading.
 */
interface TemplateRuleInterface
{
    /**
     * Stable handle, used as the finding's target and in settings.
     */
    public function handle(): string;

    /**
     * Headline for the finding this rule produces.
     */
    public function title(): string;

    /**
     * The severity when this rule matches outside a `{% cache %}` block.
     */
    public function severity(): string;

    /**
     * What it costs, in plain language.
     */
    public function impact(): string;

    /**
     * How to fix it, as GitHub-flavoured Markdown.
     */
    public function remediation(): string;

    public function docsUrl(): ?string;

    /**
     * Every match in one template.
     *
     * @return TemplateIssue[]
     */
    public function analyze(TemplateSource $source, TemplateStructure $structure): array;
}
