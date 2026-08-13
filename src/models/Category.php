<?php

namespace justinholtweb\microscope\models;

use Craft;

/**
 * The six areas Microscope looks at.
 *
 * Categories exist so a report can say *where* the site is losing time, not just how much. They
 * also map onto who fixes what: `php`, `database` and `server` are usually a hosting change,
 * `craft` is a config commit, and `schema` and `templates` are development work.
 */
final class Category
{
    public const PHP = 'php';
    public const DATABASE = 'database';
    public const SERVER = 'server';
    public const CRAFT = 'craft';
    public const SCHEMA = 'schema';
    public const TEMPLATES = 'templates';

    /**
     * All categories, in the order they are presented everywhere — roughly outside-in, from the
     * machine the site runs on to the code running on it.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PHP,
            self::DATABASE,
            self::SERVER,
            self::CRAFT,
            self::SCHEMA,
            self::TEMPLATES,
        ];
    }

    public static function exists(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::PHP => Craft::t('microscope', 'PHP'),
            self::DATABASE => Craft::t('microscope', 'Database'),
            self::SERVER => Craft::t('microscope', 'Server'),
            self::CRAFT => Craft::t('microscope', 'Craft configuration'),
            self::SCHEMA => Craft::t('microscope', 'Content schema'),
            self::TEMPLATES => Craft::t('microscope', 'Templates'),
            default => $category,
        };
    }

    public static function description(string $category): string
    {
        return match ($category) {
            self::PHP => Craft::t('microscope', 'The PHP runtime: version, OPcache, memory and the extensions Craft leans on.'),
            self::DATABASE => Craft::t('microscope', 'MySQL or MariaDB server tuning, plus the size and shape of Craft’s own tables.'),
            self::SERVER => Craft::t('microscope', 'The web server, transport and the hardware underneath it.'),
            self::CRAFT => Craft::t('microscope', 'Craft’s general config: caching, queue handling, garbage collection and image handling.'),
            self::SCHEMA => Craft::t('microscope', 'How the content is modelled — fields, layouts, sections and the volume of content in them.'),
            self::TEMPLATES => Craft::t('microscope', 'Twig templates, read statically for query patterns that cost a request more than they need to.'),
            default => '',
        };
    }

    /**
     * A Craft CP icon name for the category, used in the nav and on the dashboard.
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
