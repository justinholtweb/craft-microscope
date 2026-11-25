<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\TemplateAnalysis;

use JustinHoltWeb\Microscope\Services\Templates;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\CountViaAllRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\ImageDimensionsRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\IncludeInLoopRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\MissingCacheTagRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\QueryInLoopRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\RelationInLoopRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\TransformInLoopRule;
use JustinHoltWeb\Microscope\TemplateAnalysis\Rules\UnboundedQueryRule;

/**
 * Runs the template rules over template sources.
 *
 * Has no idea where templates come from. {@see Templates}
 * handles the filesystem; this handles the analysis, which is what makes the entire template
 * category testable against strings.
 */
class TemplateAnalyzer
{
    /**
     * @param  TemplateRuleInterface[]  $rules
     */
    public function __construct(private array $rules = [])
    {
        if ($this->rules === []) {
            $this->rules = self::defaultRules();
        }
    }

    /**
     * The rules that ship with Microscope, in the order their findings are reported.
     *
     * @return TemplateRuleInterface[]
     */
    public static function defaultRules(): array
    {
        return [
            new QueryInLoopRule,
            new RelationInLoopRule,
            new UnboundedQueryRule,
            new TransformInLoopRule,
            new CountViaAllRule,
            new MissingCacheTagRule,
            new IncludeInLoopRule,
            new ImageDimensionsRule,
        ];
    }

    /**
     * @return TemplateRuleInterface[]
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function rule(string $handle): ?TemplateRuleInterface
    {
        foreach ($this->rules as $rule) {
            if ($rule->handle() === $handle) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Drops rules the site has switched off.
     *
     * @param  string[]  $handles
     */
    public function only(array $handles): self
    {
        return new self(array_values(array_filter(
            $this->rules,
            fn (TemplateRuleInterface $rule) => in_array($rule->handle(), $handles, true),
        )));
    }

    /**
     * Every issue in one template.
     *
     * The structure is parsed once and handed to every rule — it's the expensive part, and eight
     * rules each re-walking the tags of every template on the site adds up.
     *
     * @return TemplateIssue[]
     */
    public function analyze(TemplateSource $source): array
    {
        $structure = TemplateStructure::of($source);
        $issues = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->analyze($source, $structure) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * Issues across many templates, grouped by rule handle.
     *
     * @param  iterable<TemplateSource>  $sources
     * @return array<string, TemplateIssue[]>
     */
    public function analyzeAll(iterable $sources): array
    {
        $grouped = [];

        foreach ($sources as $source) {
            foreach ($this->analyze($source) as $issue) {
                $grouped[$issue->rule][] = $issue;
            }
        }

        return $grouped;
    }
}
