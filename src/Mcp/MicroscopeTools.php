<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Mcp;

use CraftCms\Cms\Mcp\Attributes\RequiresPermission;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\User\Contracts\CraftUser;
use JustinHoltWeb\Microscope\Checks\CheckInterface;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;
use JustinHoltWeb\Microscope\Support\Presenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Microscope's tools for Craft's MCP server (registered in Plugin::boot()).
 *
 * Every tool acts as the MCP actor and makes the permission checks the control panel makes. Tool
 * names and output shapes are final — public approvals are stored by name — and identical to
 * Microscope 5.1's (Craft 5.12.1+).
 */
class MicroscopeTools
{
    private const array CATEGORIES = ['php', 'database', 'server', 'craft', 'schema', 'templates'];

    /** For optional filters: `null` must be in the enum too, or an explicit null is rejected. */
    private const array CATEGORY_FILTER = [...self::CATEGORIES, null];

    private const array SEVERITY_FILTER = ['critical', 'warning', 'notice', 'pass', null];

    public function __construct(
        private readonly McpActor $actor,
        private readonly Scans $scans,
        private readonly Checks $checks,
        private readonly Schedules $schedules,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'microscope_status',
        description: 'The site’s current Microscope performance score: the latest completed scan (score 0–100, letter grade, problem counts), the score per area (php, database, server, craft, schema, templates; null where the latest scan didn’t cover it), how many problems are new or resolved since the scan before, and the scan schedule.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function status(): array
    {
        $this->user(Plugin::PERMISSION_VIEW);
        $settings = $this->settings;
        $latest = $this->scans->getLatestScan();

        $newProblems = 0;
        $resolvedProblems = 0;

        if ($latest !== null) {
            $comparison = $this->scans->compareWithPrevious($latest);
            $newProblems = count($comparison['new']);
            $resolvedProblems = count($comparison['resolved']);
        }

        $categories = [];

        foreach (Category::all() as $category) {
            $categories[] = [
                'category' => $category,
                'label' => Category::label($category),
                'score' => isset($latest?->categoryScores[$category]) ? (int) $latest->categoryScores[$category] : null,
            ];
        }

        return [
            'scanned' => $latest !== null,
            'latestScan' => $latest !== null ? $this->summary($latest) : null,
            'categories' => $categories,
            'newProblems' => $newProblems,
            'resolvedProblems' => $resolvedProblems,
            'schedule' => [
                'enabled' => $settings->scheduleEnabled,
                'frequency' => $settings->scheduleFrequency,
                'nextRunAt' => Presenter::date($this->schedules->nextRunAt()),
            ],
        ];
    }

    /**
     * @return array{total: int, scans: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'microscope_scans_list',
        description: 'Lists Microscope scans, newest first, with each one’s score, grade, problem counts and per-area scores. Includes running and failed scans (see status and error).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function listScans(
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);

        return [
            'total' => $this->scans->getTotalScans(),
            'scans' => array_values(array_map(
                fn (Scan $scan) => $this->summary($scan),
                $this->scans->getScans(max(1, min(100, $limit)), max(0, $offset)),
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'microscope_scan_get',
        description: 'One Microscope scan’s findings, most severe first. Each finding says what was measured (observed) against what it should be (expected), why it costs performance (impact) and how to fix it (remediation, Markdown with config snippets). isNew and resolved compare against the scan before. Omit scanId for the latest completed scan. Passing checks are left out unless includePasses is true.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function getScan(
        #[Schema(minimum: 1, description: 'The scan’s ID, from microscope_scans_list. Defaults to the latest completed scan.')]
        ?int $scanId = null,
        #[Schema(enum: self::CATEGORY_FILTER, description: 'Only findings in this area.')]
        ?string $category = null,
        #[Schema(enum: self::SEVERITY_FILTER, description: 'Only findings of this severity.')]
        ?string $severity = null,
        #[Schema(description: 'Also list the checks that passed.')]
        bool $includePasses = false,
        #[Schema(description: 'Only findings that weren’t in the scan before.')]
        bool $onlyNew = false,
        #[Schema(description: 'Include each finding’s supporting rows (the templates, fields or tables behind it), up to 25 per finding.')]
        bool $includeEvidence = true,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $scanId ??= $this->scans->getLatestScan()?->id;
        $scan = $scanId !== null ? $this->scans->getScanWithResult($scanId) : null;

        if ($scan === null || $scan->result === null) {
            throw new ToolCallException('Scan not found.');
        }

        $comparison = $this->scans->compareWithPrevious($scan);
        $new = $comparison['new'];

        return [
            'scan' => $this->summary($scan),
            'newCount' => count($new),
            'findings' => Presenter::findings(
                $scan->result,
                Presenter::fingerprints($new),
                $category,
                $severity,
                $includePasses,
                $onlyNew,
                $includeEvidence,
            ),
            'resolved' => array_values(array_map(Presenter::resolved(...), $comparison['resolved'])),
            'skipped' => Presenter::skipped($scan->skipped),
        ];
    }

    /**
     * @return array{checks: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'microscope_checks_list',
        description: 'Lists the checks Microscope runs, by area, with what each looks at and whether it’s turned on for this site.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_VIEW)]
    public function listChecks(
        #[Schema(enum: self::CATEGORY_FILTER, description: 'Only checks in this area.')]
        ?string $category = null,
    ): array {
        $this->user(Plugin::PERMISSION_VIEW);
        $checks = array_filter(
            $this->checks->all(),
            fn (CheckInterface $check) => $category === null || $check->category() === $category,
        );

        return [
            'checks' => array_values(array_map(fn (CheckInterface $check) => [
                'handle' => $check->handle(),
                'category' => $check->category(),
                'label' => $check->label(),
                'description' => $check->description(),
                'enabled' => $this->settings->checkIsEnabled($check->handle()),
            ], $checks)),
        ];
    }

    /**
     * @param  list<string>|null  $categories
     * @return array{queued: bool, categories: list<string>, message: string}
     */
    #[McpTool(
        name: 'microscope_scan_run',
        description: 'Starts a Microscope scan of the whole site, or of the areas given. The scan goes on Craft’s queue and runs when the queue does; it isn’t run here. Once it finishes it is the newest entry in microscope_scans_list. A scan run by a console queue worker skips the php.ini checks (memory and request limits, realpath cache), because the CLI loads its own php.ini.',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_SCAN)]
    public function runScan(
        #[Schema(items: ['type' => 'string', 'enum' => self::CATEGORIES], description: 'Areas to scan. Omit or leave empty for all of them.')]
        ?array $categories = null,
    ): array {
        $user = $this->user(Plugin::PERMISSION_SCAN);
        $categories = array_values(array_unique(array_map('strval', $categories ?? [])));

        foreach ($categories as $category) {
            if (! Category::exists($category)) {
                throw new ToolCallException(sprintf('Unknown category: %s', $category));
            }
        }

        $config = new ScanConfig(categories: $categories, trigger: ScanConfig::TRIGGER_MANUAL);

        $this->scans->queueScan($config, $user->getCraftUserId());

        return [
            'queued' => true,
            'categories' => $config->resolvedCategories(),
            'message' => 'The scan is on the queue. It appears in microscope_scans_list once the queue has run it.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Scan $scan): array
    {
        return Presenter::scan($scan, $scan->cpUrl());
    }

    /**
     * The actor, if they hold `$permission`. Craft's server already hides the tool from anyone
     * without it; this keeps the handler safe when it's called some other way.
     */
    private function user(string $permission): CraftUser
    {
        $user = $this->actor->user();

        if (! $user->can('accessCp') || ! $user->can($permission)) {
            throw new ToolCallException('You don’t have permission to do that.');
        }

        return $user;
    }
}
