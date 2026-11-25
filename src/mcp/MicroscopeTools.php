<?php

namespace justinholtweb\microscope\mcp;

use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use justinholtweb\microscope\checks\CheckInterface;
use justinholtweb\microscope\helpers\Presenter;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\Plugin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Microscope's tools for Craft's MCP server (Craft 5.12.1+; registered by Plugin::registerMcp()).
 *
 * Every tool acts as the MCP actor and makes the permission checks the control panel makes.
 * Plugin services are reached through Plugin::getInstance(): the MCP container would autowire
 * fresh, unconfigured instances if they were constructor arguments.
 *
 * Tool names and output shapes are final — public approvals are stored by name — and shared with
 * Microscope 6.
 */
class MicroscopeTools
{
    private const CATEGORIES = ['php', 'database', 'server', 'craft', 'schema', 'templates'];

    /** For optional filters: `null` must be in the enum too, or an explicit null is rejected. */
    private const CATEGORY_FILTER = [...self::CATEGORIES, null];
    private const SEVERITY_FILTER = ['critical', 'warning', 'notice', 'pass', null];

    public function __construct(private McpActor $actor)
    {
    }

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
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $latest = $plugin->scans->getLatestScan();

        $newProblems = 0;
        $resolvedProblems = 0;

        if ($latest !== null) {
            $result = $plugin->scans->getResultForScan((int)$latest->id);
            $previous = $plugin->scans->getPreviousScan((int)$latest->id);
            $previousResult = $previous !== null ? $plugin->scans->getResultForScan((int)$previous->id) : null;
            $newProblems = count($result->newSince($previousResult));
            $resolvedProblems = count($result->resolvedSince($previousResult));
        }

        $categories = [];

        foreach (Category::all() as $category) {
            $categories[] = [
                'category' => $category,
                'label' => Category::label($category),
                'score' => isset($latest?->categoryScores[$category]) ? (int)$latest->categoryScores[$category] : null,
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
                'nextRunAt' => Presenter::date($plugin->schedules->nextRunAt()),
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
        $scans = Plugin::getInstance()->scans;

        return [
            'total' => $scans->getTotalScans(),
            'scans' => array_values(array_map(
                fn(Scan $scan) => $this->summary($scan),
                $scans->getScans(max(1, min(100, $limit)), max(0, $offset)),
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
        $scans = Plugin::getInstance()->scans;

        if ($scanId === null) {
            $scanId = $scans->getLatestScan()?->id;
        }

        $scan = $scanId !== null ? $scans->getScanWithResult($scanId) : null;

        if ($scan === null || $scan->result === null) {
            throw new ToolCallException('Scan not found.');
        }

        $previous = $scans->getPreviousScan((int)$scan->id);
        $previousResult = $previous !== null ? $scans->getResultForScan((int)$previous->id) : null;
        $new = $scan->result->newSince($previousResult);

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
            'resolved' => array_values(array_map(
                [Presenter::class, 'resolved'],
                $scan->result->resolvedSince($previousResult),
            )),
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
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $checks = array_filter(
            $plugin->checks->getAllChecks(),
            fn(CheckInterface $check) => $category === null || $check->category() === $category,
        );

        return [
            'checks' => array_values(array_map(fn(CheckInterface $check) => [
                'handle' => $check->handle(),
                'category' => $check->category(),
                'label' => $check->label(),
                'description' => $check->description(),
                'enabled' => $settings->checkIsEnabled($check->handle()),
            ], $checks)),
        ];
    }

    /**
     * @param string[]|null $categories
     * @return array{queued: bool, categories: string[], message: string}
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
            if (!Category::exists($category)) {
                throw new ToolCallException(sprintf('Unknown category: %s', $category));
            }
        }

        $config = new ScanConfig();
        $config->trigger = ScanConfig::TRIGGER_MANUAL;
        $config->categories = $categories;

        Plugin::getInstance()->scans->queueScan($config, (int)$user->id);

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
        return Presenter::scan($scan, UrlHelper::cpUrl('microscope/scans/' . $scan->id));
    }

    /**
     * The actor, if they hold `$permission`. Craft's server already hides the tool from anyone
     * without it; this keeps the handler safe when it's called some other way.
     */
    private function user(string $permission): User
    {
        $user = $this->actor->user();

        if (!$user->can('accessCp') || !$user->can($permission)) {
            throw new ToolCallException('You don’t have permission to do that.');
        }

        return $user;
    }
}
