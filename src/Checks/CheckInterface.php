<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks;

use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\Finding;

/**
 * One thing Microscope knows how to look at.
 *
 * Checks are stateless and self-describing: the catalogue screen is built by asking every
 * registered check what it is, and settings enable and disable them by handle. A check returns
 * findings — including passing ones, so a report can say "OPcache is configured correctly" rather
 * than leaving the reader to infer it from silence.
 */
interface CheckInterface
{
    /** Stable handle. Used in settings, in a finding's fingerprint, and on the CLI. */
    public function handle(): string;

    /** Which {@see Category} this belongs to. */
    public function category(): string;

    /** Short name for the catalogue, e.g. "OPcache". */
    public function label(): string;

    /** A sentence explaining what the check looks at. */
    public function description(): string;

    /**
     * Whether this check can produce a meaningful result here. A check that can't is recorded as
     * skipped, with {@see unavailableReason()}, and doesn't count against the score: an unknown
     * value isn't a bad one.
     */
    public function isAvailable(CheckContext $context): bool;

    /** Why the check can't run here. Only consulted when {@see isAvailable()} is false. */
    public function unavailableReason(CheckContext $context): string;

    /**
     * @return list<Finding>
     */
    public function run(CheckContext $context): array;
}
