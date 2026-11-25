<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Gql\Types;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Microscope\Gql\Queries\MicroscopeQueries;
use JustinHoltWeb\Microscope\Support\Presenter;

/**
 * Microscope's GraphQL object types, resolved from the arrays {@see Presenter}
 * builds. Names and fields match Microscope 5.1.
 */
final class MicroscopeTypes
{
    public static function scan(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('MicroscopeScan', fn () => new ObjectType([
            'name' => 'MicroscopeScan',
            'description' => 'A Microscope performance scan.',
            'fields' => fn () => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending, running, completed or failed.'],
                'trigger' => ['type' => Type::nonNull(Type::string()), 'description' => 'manual, scheduled or console.'],
                'score' => ['type' => Type::int(), 'description' => '0–100.'],
                'grade' => ['type' => Type::string(), 'description' => 'A–F.'],
                'criticalCount' => ['type' => Type::nonNull(Type::int())],
                'warningCount' => ['type' => Type::nonNull(Type::int())],
                'noticeCount' => ['type' => Type::nonNull(Type::int())],
                'passCount' => ['type' => Type::nonNull(Type::int())],
                'runtime' => ['type' => Type::float(), 'description' => 'Seconds.'],
                'error' => ['type' => Type::string()],
                'dateStarted' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'dateFinished' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'dateCreated' => ['type' => Type::string(), 'description' => 'ISO-8601.'],
                'categoryScores' => ['type' => Type::listOf(self::categoryScore())],
                'skipped' => ['type' => Type::listOf(self::skippedCheck())],
                'findings' => [
                    'type' => Type::listOf(self::finding()),
                    'args' => [
                        'category' => ['type' => Type::string()],
                        'severity' => ['type' => Type::string(), 'description' => 'critical, warning, notice or pass.'],
                        'includePasses' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => MicroscopeQueries::findings(...),
                    'description' => 'Most severe first. Passing checks only with includePasses (or severity: "pass").',
                ],
            ],
        ]));
    }

    public static function categoryScore(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('MicroscopeCategoryScore', fn () => new ObjectType([
            'name' => 'MicroscopeCategoryScore',
            'description' => 'A scan’s score for one area.',
            'fields' => fn () => [
                'category' => ['type' => Type::nonNull(Type::string())],
                'label' => ['type' => Type::nonNull(Type::string())],
                'score' => ['type' => Type::int()],
            ],
        ]));
    }

    public static function skippedCheck(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('MicroscopeSkippedCheck', fn () => new ObjectType([
            'name' => 'MicroscopeSkippedCheck',
            'description' => 'A check that couldn’t run, and why.',
            'fields' => fn () => [
                'checkHandle' => ['type' => Type::nonNull(Type::string())],
                'reason' => ['type' => Type::string()],
            ],
        ]));
    }

    public static function finding(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('MicroscopeFinding', fn () => new ObjectType([
            'name' => 'MicroscopeFinding',
            'description' => 'Something a scan found, with how to fix it.',
            'fields' => fn () => [
                'checkHandle' => ['type' => Type::nonNull(Type::string())],
                'category' => ['type' => Type::nonNull(Type::string())],
                'severity' => ['type' => Type::nonNull(Type::string()), 'description' => 'critical, warning, notice or pass.'],
                'title' => ['type' => Type::nonNull(Type::string())],
                'summary' => ['type' => Type::string()],
                'observed' => ['type' => Type::string(), 'description' => 'What was measured.'],
                'expected' => ['type' => Type::string(), 'description' => 'What it should be.'],
                'impact' => ['type' => Type::string(), 'description' => 'What it costs.'],
                'remediation' => ['type' => Type::string(), 'description' => 'How to fix it, as Markdown.'],
                'docsUrl' => ['type' => Type::string()],
                'target' => ['type' => Type::string(), 'description' => 'The template, field, table or setting it’s about.'],
                'occurrences' => ['type' => Type::nonNull(Type::int())],
                'fingerprint' => ['type' => Type::nonNull(Type::string()), 'description' => 'Stable across scans: checkHandle:target.'],
            ],
        ]));
    }

    public static function check(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('MicroscopeCheck', fn () => new ObjectType([
            'name' => 'MicroscopeCheck',
            'description' => 'One of the checks Microscope runs.',
            'fields' => fn () => [
                'handle' => ['type' => Type::nonNull(Type::string())],
                'category' => ['type' => Type::nonNull(Type::string())],
                'label' => ['type' => Type::nonNull(Type::string())],
                'description' => ['type' => Type::string()],
                'enabled' => ['type' => Type::nonNull(Type::boolean())],
            ],
        ]));
    }
}
