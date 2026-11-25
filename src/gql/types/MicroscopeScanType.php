<?php

namespace justinholtweb\microscope\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\microscope\gql\resolvers\MicroscopeResolver;

/**
 * `MicroscopeScan` — A Microscope performance scan.
 */
class MicroscopeScanType
{
    public static function getName(): string
    {
        return 'MicroscopeScan';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A Microscope performance scan.',
            'fields' => fn() => [
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
                'categoryScores' => ['type' => Type::listOf(MicroscopeCategoryScoreType::getType())],
                'skipped' => ['type' => Type::listOf(MicroscopeSkippedCheckType::getType())],
                'findings' => [
                    'type' => Type::listOf(MicroscopeFindingType::getType()),
                    'args' => [
                        'category' => ['type' => Type::string()],
                        'severity' => ['type' => Type::string(), 'description' => 'critical, warning, notice or pass.'],
                        'includePasses' => ['type' => Type::boolean(), 'defaultValue' => false],
                    ],
                    'resolve' => [MicroscopeResolver::class, 'findings'],
                    'description' => 'Most severe first. Passing checks only with includePasses (or severity: "pass").',
                ],
            ],
        ]));
    }
}
