<?php

namespace justinholtweb\microscope\checks;

use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\models\Settings;
use justinholtweb\microscope\probes\CraftProbe;
use justinholtweb\microscope\probes\DatabaseProbe;
use justinholtweb\microscope\probes\PhpProbe;
use justinholtweb\microscope\probes\ServerProbe;

/**
 * Everything a check is given to work with.
 *
 * Checks never reach for `Craft::$app` or `ini_get()` directly — they go through the probes on
 * here. That is partly tidiness and mostly testability: a probe can be substituted, so a check's
 * thresholds can be tested against a fabricated server without one existing.
 *
 * The probes memoize, so ten checks reading `SHOW VARIABLES` cost one query between them.
 */
class CheckContext
{
    public function __construct(
        public readonly Settings $settings,
        public readonly ScanConfig $config,
        public readonly PhpProbe $php,
        public readonly DatabaseProbe $database,
        public readonly ServerProbe $server,
        public readonly CraftProbe $craft,
    ) {
    }

    /**
     * Whether this environment should be held to production standards.
     *
     * Several checks are only meaningful in production — `devMode` is *supposed* to be on locally,
     * and telling a developer off for it on every scan would train them to ignore the report. The
     * environment name is the signal, with `dev` and `staging` treated as not-production.
     */
    public function isProduction(): bool
    {
        return $this->craft->isProduction();
    }
}
