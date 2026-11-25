<?php

namespace justinholtweb\microscope\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `MicroscopeSkippedCheck` — A check that didn't run in a scan, and why.
 */
class MicroscopeSkippedCheckType
{
    public static function getName(): string
    {
        return 'MicroscopeSkippedCheck';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A check that didn\'t run in a scan, and why.',
            'fields' => fn() => [
                'checkHandle' => ['type' => Type::nonNull(Type::string())],
                'reason' => ['type' => Type::string()],
            ],
        ]));
    }
}
