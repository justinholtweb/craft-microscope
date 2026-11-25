<?php

namespace justinholtweb\microscope\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `MicroscopeCheck` — A check Microscope can run.
 */
class MicroscopeCheckType
{
    public static function getName(): string
    {
        return 'MicroscopeCheck';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A check Microscope can run.',
            'fields' => fn() => [
                'handle' => ['type' => Type::nonNull(Type::string())],
                'category' => ['type' => Type::nonNull(Type::string())],
                'label' => ['type' => Type::nonNull(Type::string())],
                'description' => ['type' => Type::string()],
                'enabled' => ['type' => Type::nonNull(Type::boolean())],
            ],
        ]));
    }
}
