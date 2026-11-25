<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Gql;

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Gql\GqlHelper;
use Throwable;

/**
 * Whether the active GraphQL schema may read Microscope's scans.
 *
 * Scans describe the server — PHP and database settings, template paths, what's misconfigured —
 * so they're only for a token's schema with `microscopeScans:read`, and never for the public
 * schema, whatever its scopes.
 */
final class GqlAccess
{
    /** The schema component; the permission is `microscopeScans:read`. */
    public const string SCOPE = 'microscopeScans';

    public static function canRead(?GqlSchema $schema = null): bool
    {
        try {
            $schema ??= app(Gql::class)->getActiveSchema();
        } catch (Throwable) {
            return false;
        }

        return ! $schema->isPublic && GqlHelper::canSchema(self::SCOPE, 'read', $schema);
    }
}
