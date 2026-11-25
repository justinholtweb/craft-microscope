<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Gql\Queries;

use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Microscope\Checks\CheckInterface;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Gql\GqlAccess;
use JustinHoltWeb\Microscope\Gql\Types\MicroscopeTypes;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Support\Presenter;
use Override;

/**
 * `microscopeScans`, `microscopeScan`, `microscopeChecks`. Read-only, and only for a schema with
 * `microscopeScans:read` that isn't the public schema ({@see GqlAccess}). No environment, evidence,
 * creator or control panel URLs over GraphQL.
 */
final class MicroscopeQueries extends Query
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlAccess::canRead()) {
            return [];
        }

        return [
            'microscopeScans' => [
                'type' => Type::listOf(MicroscopeTypes::scan()),
                'args' => [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => self::scans(...),
                'description' => 'Microscope scans, newest first.',
            ],
            'microscopeScan' => [
                'type' => MicroscopeTypes::scan(),
                'args' => [
                    'id' => ['type' => Type::int(), 'description' => 'Omit for the latest completed scan.'],
                ],
                'resolve' => self::scan(...),
                'description' => 'One Microscope scan, or the latest completed one.',
            ],
            'microscopeChecks' => [
                'type' => Type::listOf(MicroscopeTypes::check()),
                'args' => [
                    'category' => ['type' => Type::string(), 'description' => 'php, database, server, craft, schema or templates.'],
                ],
                'resolve' => self::checks(...),
                'description' => 'The checks Microscope runs, and whether each is turned on.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function scans(mixed $source, array $arguments): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        return array_values(array_map(self::scanData(...), app(Scans::class)->getScans($limit, $offset)));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function scan(mixed $source, array $arguments): ?array
    {
        if (! GqlAccess::canRead()) {
            return null;
        }

        $scans = app(Scans::class);
        $scan = isset($arguments['id']) ? $scans->getScanById((int) $arguments['id']) : $scans->getLatestScan();

        return $scan !== null ? self::scanData($scan) : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function checks(mixed $source, array $arguments): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        $settings = app(Settings::class);
        $category = $arguments['category'] ?? null;

        $checks = array_filter(
            app(Checks::class)->all(),
            fn (CheckInterface $check) => $category === null || $check->category() === $category,
        );

        return array_values(array_map(fn (CheckInterface $check) => [
            'handle' => $check->handle(),
            'category' => $check->category(),
            'label' => $check->label(),
            'description' => $check->description(),
            'enabled' => $settings->checkIsEnabled($check->handle()),
        ], $checks));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function findings(array $source, array $arguments): array
    {
        if (! GqlAccess::canRead()) {
            return [];
        }

        $result = app(Scans::class)->getResultForScan((int) $source['id']);
        $category = $arguments['category'] ?? null;
        $severity = $arguments['severity'] ?? null;

        return array_map(
            // No evidence and no isNew over GraphQL: the type doesn't have them.
            fn (array $finding) => array_diff_key($finding, ['isNew' => true]),
            Presenter::findings(
                $result,
                category: is_string($category) ? $category : null,
                severity: is_string($severity) ? $severity : null,
                includePasses: (bool) ($arguments['includePasses'] ?? false),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function scanData(Scan $scan): array
    {
        $data = Presenter::scan($scan);
        unset($data['url']);

        $data['categoryScores'] = [];

        foreach ($scan->categoryScores ?? [] as $category => $score) {
            $data['categoryScores'][] = [
                'category' => (string) $category,
                'label' => Category::label((string) $category),
                'score' => (int) $score,
            ];
        }

        $data['skipped'] = Presenter::skipped($scan->skipped);

        return $data;
    }
}
