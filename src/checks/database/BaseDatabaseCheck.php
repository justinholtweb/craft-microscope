<?php

namespace justinholtweb\microscope\checks\database;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * Shared gating for the database checks.
 *
 * All of them are MySQL/MariaDB-specific — Craft also supports PostgreSQL, where none of this
 * advice applies — and all of them depend on `SHOW VARIABLES` and `SHOW GLOBAL STATUS`, which
 * managed database services routinely restrict. Both are handled once, here, so the individual
 * checks can assume they have data.
 */
abstract class BaseDatabaseCheck extends BaseCheck
{
    public function category(): string
    {
        return Category::DATABASE;
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $context->database->isMysql() && $context->database->variables() !== [];
    }

    public function unavailableReason(CheckContext $context): string
    {
        if (!$context->database->isMysql()) {
            return Craft::t('microscope', 'This check only applies to MySQL and MariaDB. The site is running {driver}.', [
                'driver' => 'PostgreSQL',
            ]);
        }

        return Craft::t('microscope', 'The database user can’t read server variables. Managed database services often restrict SHOW VARIABLES; grant the Craft user PROCESS privileges, or run scans as a user that has them, to enable these checks.');
    }

    /**
     * Whether the server has been up long enough for its status counters to mean anything.
     *
     * Every ratio in this category — temporary tables on disk, buffer pool hit rate, thread cache
     * efficiency — is cumulative since startup. On a server that restarted twenty minutes ago they
     * describe the warm-up, not the tuning.
     */
    protected function hasMeaningfulStatistics(CheckContext $context): bool
    {
        $uptime = $context->database->uptime();

        return $uptime !== null && $uptime > 3600;
    }
}
