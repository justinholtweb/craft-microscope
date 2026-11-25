<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Database;

use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Support\Version;

use function CraftCms\Cms\t;

/**
 * How old the database server is.
 */
class DatabaseVersionCheck extends BaseDatabaseCheck
{
    private const MIN_MYSQL = '8.0';

    private const MIN_MARIADB = '10.5';

    public function handle(): string
    {
        return 'databaseVersion';
    }

    public function label(): string
    {
        return t('Database version', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the MySQL or MariaDB version against the one Craft is tuned for.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $db = $context->database;
        $version = $db->version();
        $name = $db->serverName();
        $minimum = $db->isMariaDb() ? self::MIN_MARIADB : self::MIN_MYSQL;

        if ($version === null) {
            return [];
        }

        if (Version::lessThan($version, $minimum)) {
            return [$this->warning([
                'title' => t('{name} is older than recommended', ['name' => $name], category: 'microscope'),
                'summary' => t('The site is on {name} {version}. {minimum} or later has a substantially better query optimizer.', [
                    'name' => $name,
                    'version' => $version,
                    'minimum' => $minimum,
                ], category: 'microscope'),
                'observed' => $name.' '.$version,
                'expected' => $name.' '.$minimum.'+',
                'target' => 'database.version',
                'impact' => t('Craft leans on the optimizer heavily — element queries join `elements`, `elements_sites`, `content` and one table per relation, and the plan chosen for those joins is what decides whether a listing page takes 5 ms or 500 ms. Newer servers also support descending indexes, common table expressions and window functions, all of which Craft and its plugins use where available.', category: 'microscope'),
                'remediation' => $this->instructions($name),
                'docsUrl' => 'https://craftcms.com/docs/5.x/system/requirements.html',
            ])];
        }

        return [$this->pass(
            t('Database server is a current version', category: 'microscope'),
            $name.' '.$version,
        )];
    }

    private function instructions(string $name): string
    {
        return <<<MD
Upgrading a database server is not a config change, so plan it properly:

1. **Take a backup you have actually restored from.** `php artisan craft:db:backup` produces one Craft can
   restore; verify it on a scratch database before touching production.
2. **Check character sets first.** Craft 4 and 5 default to `utf8mb4`; installs upgraded from
   Craft 2 or 3 are often still on `utf8`, and moving between {$name} versions is the natural
   moment to fix that. Compare with:
   ```sql
   SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
   FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE();
   ```
3. **Upgrade on a staging copy and run the site against it** before doing anything in production.
   The optimizer changes between major versions, which is the point — but it means a query that
   was fast can occasionally become slow, and you want to find that on staging.
4. **Re-analyse the tables afterwards** so the new optimizer has fresh statistics:
   ```sql
   ANALYZE TABLE elements, elements_sites, entries, relations, searchindex;
   ```
   Run this during a quiet period; it takes a brief read lock per table.

On managed hosting (RDS, DigitalOcean, PlanetScale) this is usually a version selector plus a
maintenance window rather than anything hands-on — but the staging rehearsal still applies.
MD;
    }
}
