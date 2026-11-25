<?php

namespace justinholtweb\microscope\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `MicroscopeFinding` — One thing a Microscope scan found, with how to fix it.
 */
class MicroscopeFindingType
{
    public static function getName(): string
    {
        return 'MicroscopeFinding';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'One thing a Microscope scan found, with how to fix it.',
            'fields' => fn() => [
                'checkHandle' => ['type' => Type::nonNull(Type::string())],
                'category' => ['type' => Type::nonNull(Type::string())],
                'severity' => ['type' => Type::nonNull(Type::string()), 'description' => 'critical, warning, notice or pass.'],
                'title' => ['type' => Type::nonNull(Type::string())],
                'summary' => ['type' => Type::string()],
                'observed' => ['type' => Type::string(), 'description' => 'What was measured.'],
                'expected' => ['type' => Type::string(), 'description' => 'What it should be.'],
                'impact' => ['type' => Type::string(), 'description' => 'What it costs.'],
                'remediation' => ['type' => Type::string(), 'description' => 'How to fix it, in Markdown.'],
                'docsUrl' => ['type' => Type::string()],
                'target' => ['type' => Type::string(), 'description' => 'The template, field, table or setting it is about.'],
                'occurrences' => ['type' => Type::nonNull(Type::int())],
                'fingerprint' => ['type' => Type::nonNull(Type::string()), 'description' => 'Identifies the same finding across scans.'],
            ],
        ]));
    }
}
