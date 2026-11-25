<?php

namespace justinholtweb\microscope\gql;

use Craft;
use craft\errors\GqlException;
use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;

/**
 * Whether the active GraphQL schema may read Microscope's scans.
 *
 * Scans describe the server — PHP and database settings, template paths, what's misconfigured —
 * so they're only for a token's schema that has `microscopeScans:read`, and never for the public
 * schema, whatever its scopes.
 */
class GqlAccess
{
    /** The schema component; the permission is `microscopeScans:read`. */
    public const SCOPE = 'microscopeScans';

    public static function canRead(?GqlSchema $schema = null): bool
    {
        if ($schema === null) {
            try {
                $schema = Craft::$app->getGql()->getActiveSchema();
            } catch (GqlException) {
                return false;
            }
        }

        return !$schema->isPublic && GqlHelper::canSchema(self::SCOPE, 'read', $schema);
    }
}
