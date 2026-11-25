<?php

namespace justinholtweb\microscope\gql\resolvers;

use justinholtweb\microscope\checks\CheckInterface;
use justinholtweb\microscope\gql\GqlAccess;
use justinholtweb\microscope\helpers\Presenter;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\Plugin;

/**
 * Resolvers for Microscope's GraphQL queries. Each re-checks the active schema, so a query that
 * slipped into a schema without the scope, or the public schema, still returns nothing.
 *
 * Scans resolve to {@see Presenter::scan()} arrays (minus the CP URL) plus what the
 * `MicroscopeScan` type needs on top: category scores as a list, skipped checks, and the scan's
 * ID for the lazily-resolved `findings` field.
 */
class MicroscopeResolver
{
    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function scans(mixed $source, array $arguments): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $limit = max(1, min(100, (int)($arguments['limit'] ?? 20)));
        $offset = max(0, (int)($arguments['offset'] ?? 0));

        return array_values(array_map(
            [self::class, 'scanData'],
            Plugin::getInstance()->scans->getScans($limit, $offset),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function scan(mixed $source, array $arguments): ?array
    {
        if (!GqlAccess::canRead()) {
            return null;
        }

        $scans = Plugin::getInstance()->scans;
        $scan = isset($arguments['id'])
            ? $scans->getScanById((int)$arguments['id'])
            : $scans->getLatestScan();

        return $scan !== null ? self::scanData($scan) : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function checks(mixed $source, array $arguments): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $category = $arguments['category'] ?? null;

        $checks = array_filter(
            $plugin->checks->getAllChecks(),
            fn(CheckInterface $check) => $category === null || $check->category() === $category,
        );

        return array_values(array_map(fn(CheckInterface $check) => [
            'handle' => $check->handle(),
            'category' => $check->category(),
            'label' => $check->label(),
            'description' => $check->description(),
            'enabled' => $settings->checkIsEnabled($check->handle()),
        ], $checks));
    }

    /**
     * `MicroscopeScan.findings`.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function findings(array $source, array $arguments): array
    {
        if (!GqlAccess::canRead()) {
            return [];
        }

        $result = Plugin::getInstance()->scans->getResultForScan((int)$source['id']);
        $category = $arguments['category'] ?? null;
        $severity = $arguments['severity'] ?? null;

        return array_map(
            // No evidence and no isNew over GraphQL: the type doesn't have them.
            fn(array $finding) => array_diff_key($finding, ['isNew' => true]),
            Presenter::findings(
                $result,
                category: is_string($category) ? $category : null,
                severity: is_string($severity) ? $severity : null,
                includePasses: (bool)($arguments['includePasses'] ?? false),
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

        foreach ($scan->categoryScores as $category => $score) {
            $data['categoryScores'][] = [
                'category' => (string)$category,
                'label' => Category::label((string)$category),
                'score' => (int)$score,
            ];
        }

        $data['skipped'] = Presenter::skipped($scan->skipped);

        return $data;
    }
}
