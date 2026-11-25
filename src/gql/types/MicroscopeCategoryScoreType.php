<?php

namespace justinholtweb\microscope\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `MicroscopeCategoryScore` — A scan's score for one area, 0–100.
 */
class MicroscopeCategoryScoreType
{
    public static function getName(): string
    {
        return 'MicroscopeCategoryScore';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A scan\'s score for one area, 0–100.',
            'fields' => fn() => [
                'category' => ['type' => Type::nonNull(Type::string()), 'description' => 'php, database, server, craft, schema or templates.'],
                'label' => ['type' => Type::nonNull(Type::string())],
                'score' => ['type' => Type::int()],
            ],
        ]));
    }
}
