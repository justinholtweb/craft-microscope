<?php

namespace justinholtweb\microscope\checks;

/**
 * One thing Microscope knows how to look at.
 *
 * Checks are stateless and self-describing: the catalogue screen in the control panel is built by
 * asking every registered check what it is, and settings enable and disable them by handle. A
 * check returns findings — including passing ones, which is what lets a report say "OPcache is
 * configured correctly" rather than leaving the reader to infer it from silence.
 */
interface CheckInterface
{
    /**
     * Stable handle. Used in settings, as part of a finding's fingerprint, and on the CLI.
     */
    public function handle(): string;

    /**
     * Which {@see \justinholtweb\microscope\models\Category} this belongs to.
     */
    public function category(): string;

    /**
     * Short name for the catalogue, e.g. "OPcache".
     */
    public function label(): string;

    /**
     * A sentence explaining what the check looks at, shown in the catalogue.
     */
    public function description(): string;

    /**
     * Whether this check can produce a meaningful result in this environment.
     *
     * A check that returns false is recorded as skipped, with {@see unavailableReason()}, and
     * doesn't count against the site's score. That distinction matters: a shared host that won't
     * report its `innodb_buffer_pool_size` hasn't got a small buffer pool, it has an unknown one,
     * and scoring it down would be making something up.
     */
    public function isAvailable(CheckContext $context): bool;

    /**
     * Why the check can't run here. Only consulted when {@see isAvailable()} is false.
     */
    public function unavailableReason(CheckContext $context): string;

    /**
     * @return \justinholtweb\microscope\models\Finding[]
     */
    public function run(CheckContext $context): array;
}
