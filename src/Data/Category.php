<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Data;

use function CraftCms\Cms\t;

/**
 * The six areas Microscope looks at.
 *
 * Categories exist so a report can say *where* the site is losing time, not just how much. They
 * also map onto who fixes what: `php`, `database` and `server` are usually a hosting change,
 * `craft` is a config commit, and `schema` and `templates` are development work.
 */
final class Category
{
    public const string PHP = 'php';

    public const string DATABASE = 'database';

    public const string SERVER = 'server';

    public const string CRAFT = 'craft';

    public const string SCHEMA = 'schema';

    public const string TEMPLATES = 'templates';

    /**
     * All categories, in the order they are presented everywhere — roughly outside-in, from the
     * machine the site runs on to the code running on it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::PHP, self::DATABASE, self::SERVER, self::CRAFT, self::SCHEMA, self::TEMPLATES];
    }

    public static function exists(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::PHP => t('PHP', category: 'microscope'),
            self::DATABASE => t('Database', category: 'microscope'),
            self::SERVER => t('Server', category: 'microscope'),
            self::CRAFT => t('Craft configuration', category: 'microscope'),
            self::SCHEMA => t('Content schema', category: 'microscope'),
            self::TEMPLATES => t('Templates', category: 'microscope'),
            default => $category,
        };
    }

    public static function description(string $category): string
    {
        return match ($category) {
            self::PHP => t('The PHP runtime: version, OPcache, memory and the extensions Craft leans on.', category: 'microscope'),
            self::DATABASE => t('MySQL, MariaDB or PostgreSQL server tuning, plus the size and shape of Craft’s own tables.', category: 'microscope'),
            self::SERVER => t('The web server, transport and the hardware underneath it.', category: 'microscope'),
            self::CRAFT => t('Craft and Laravel configuration: debug mode, caching, the queue, sessions, garbage collection and image handling.', category: 'microscope'),
            self::SCHEMA => t('How the content is modelled — fields, layouts, sections and the volume of content in them.', category: 'microscope'),
            self::TEMPLATES => t('Twig templates, read statically for query patterns that cost a request more than they need to.', category: 'microscope'),
            default => '',
        };
    }

    /**
     * A Craft CP icon name for the category, used on the dashboard.
     */
    public static function icon(string $category): string
    {
        return match ($category) {
            self::PHP => 'code',
            self::DATABASE => 'database',
            self::SERVER => 'server',
            self::CRAFT => 'sliders',
            self::SCHEMA => 'diagram-project',
            self::TEMPLATES => 'file-lines',
            default => 'circle-info',
        };
    }
}
