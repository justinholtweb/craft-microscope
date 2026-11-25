<?php

namespace justinholtweb\microscope\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use justinholtweb\microscope\gql\GqlAccess;
use justinholtweb\microscope\gql\resolvers\MicroscopeResolver;
use justinholtweb\microscope\gql\types\MicroscopeCheckType;
use justinholtweb\microscope\gql\types\MicroscopeScanType;

/**
 * Microscope's read-only GraphQL queries. In the schema only when it has `microscopeScans:read`
 * and isn't the public schema.
 */
class MicroscopeQueries extends Query
{
    /**
     * @return array<string, mixed>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlAccess::canRead()) {
            return [];
        }

        return [
            'microscopeScans' => [
                'type' => Type::listOf(MicroscopeScanType::getType()),
                'args' => [
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => [MicroscopeResolver::class, 'scans'],
                'description' => 'Microscope scans, newest first.',
            ],
            'microscopeScan' => [
                'type' => MicroscopeScanType::getType(),
                'args' => [
                    'id' => ['type' => Type::int(), 'description' => 'Omit for the latest completed scan.'],
                ],
                'resolve' => [MicroscopeResolver::class, 'scan'],
                'description' => 'One Microscope scan, or the latest completed one.',
            ],
            'microscopeChecks' => [
                'type' => Type::listOf(MicroscopeCheckType::getType()),
                'args' => [
                    'category' => ['type' => Type::string(), 'description' => 'php, database, server, craft, schema or templates.'],
                ],
                'resolve' => [MicroscopeResolver::class, 'checks'],
                'description' => 'The checks Microscope runs, and whether each is turned on.',
            ],
        ];
    }
}
