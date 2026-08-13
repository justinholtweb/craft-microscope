<?php

namespace justinholtweb\microscope\checks\templates;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\Plugin;
use justinholtweb\microscope\templates\TemplateIssue;
use justinholtweb\microscope\templates\TemplateRuleInterface;

/**
 * Reads the site's Twig templates and reports the query patterns that cost a request more than
 * they need to.
 *
 * One check rather than one per rule, because the expensive part is reading and parsing the
 * templates and doing that eight times would be absurd. Each rule still gets its own finding, so
 * the report reads as though they were separate checks.
 *
 * Everything here is static analysis — Microscope never renders a template. That means it can't
 * see through a variable holding a query, and it will occasionally flag something that is fine in
 * context. The rules are written to prefer a missed detection over a false one, and every finding
 * lists the exact file and line so the judgement stays with a person.
 */
class TemplateCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'templates';
    }

    public function category(): string
    {
        return Category::TEMPLATES;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Template analysis');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Reads every Twig template looking for queries inside loops, unbounded queries, missing eager loading and other patterns that scale badly.');
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->settings->scanTemplates;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return Craft::t('microscope', 'Template scanning is turned off in Microscope’s settings.');
    }

    public function run(CheckContext $context): array
    {
        $templates = Plugin::getInstance()->templates;
        $sources = $templates->sources();

        if ($sources === []) {
            return [$this->notice([
                'title' => Craft::t('microscope', 'No templates were found to analyse'),
                'summary' => Craft::t('microscope', 'Microscope couldn’t read any `.twig` files from the site’s template directories.'),
                'observed' => Craft::t('microscope', '0 templates'),
                'expected' => Craft::t('microscope', 'The site’s templates'),
                'target' => 'templates.none',
                'impact' => Craft::t('microscope', 'The template category can’t contribute to this report, so any query problems in the front end are invisible to it.'),
                'remediation' => "Check that the templates path is readable by the user PHP runs as, and that it is where Craft expects:\n\n```bash\nls -la templates/\n```\n\nA headless install serving only GraphQL legitimately has no templates — in that case, turn template scanning off in Microscope's settings so this stops being reported.",
            ])];
        }

        $analyzer = $templates->analyzer();
        $grouped = $analyzer->analyzeAll($sources);
        $findings = [];

        foreach ($analyzer->rules() as $rule) {
            $issues = $grouped[$rule->handle()] ?? [];

            if ($issues === []) {
                continue;
            }

            $findings[] = $this->findingFor($rule, $issues, count($sources));
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'No template performance problems found'),
                Craft::t('microscope', '{count} templates analysed', ['count' => count($sources)]),
            );
        }

        return $findings;
    }

    /**
     * Rolls every match for one rule into a single finding.
     *
     * @param TemplateIssue[] $issues
     */
    private function findingFor(TemplateRuleInterface $rule, array $issues, int $templateCount): Finding
    {
        $files = array_unique(array_map(fn(TemplateIssue $i) => $i->templatePath, $issues));

        // Everything inside a `{% cache %}` block is a considered decision rather than an oversight,
        // so a rule whose matches are *all* cached is downgraded rather than silenced — the cost is
        // real on a cache miss, it's just been accepted.
        $allCached = !in_array(false, array_map(fn(TemplateIssue $i) => $i->cached, $issues), true);
        $severity = $allCached ? Finding::SEVERITY_NOTICE : $rule->severity();

        $finding = $this->finding($severity, [
            'title' => $rule->title(),
            'summary' => Craft::t('microscope', 'Found {count} times across {files} of {total} templates.', [
                'count' => count($issues),
                'files' => count($files),
                'total' => $templateCount,
            ]) . ($allCached ? ' ' . Craft::t('microscope', 'Every occurrence is inside a `{% cache %}` block, so the cost is paid on cache misses only.') : ''),
            'observed' => Craft::t('microscope', '{count} occurrences in {files} templates', [
                'count' => count($issues),
                'files' => count($files),
            ]),
            'target' => $rule->handle(),
            'occurrences' => count($issues),
            'impact' => $rule->impact(),
            'remediation' => $rule->remediation(),
            'docsUrl' => $rule->docsUrl(),
            'evidence' => array_map(fn(TemplateIssue $i) => $i->toArray(), $this->limitEvidence($issues)),
        ]);

        // The finding's own category comes from the check; the rule handle distinguishes findings
        // from each other in fingerprints across scans.
        $finding->checkHandle = $this->handle() . '.' . $rule->handle();

        return $finding;
    }

    /**
     * Caps how many occurrences are stored per finding.
     *
     * A site with a systemic problem can produce hundreds of matches for one rule, and storing all
     * of them bloats every scan row for no benefit — nobody works through occurrence 200. The
     * count is preserved separately, so the finding still says how many there were.
     *
     * @param TemplateIssue[] $issues
     * @return TemplateIssue[]
     */
    private function limitEvidence(array $issues, int $limit = 50): array
    {
        return array_slice($issues, 0, $limit);
    }
}
