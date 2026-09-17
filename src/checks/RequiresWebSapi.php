<?php

namespace justinholtweb\microscope\checks;

use Craft;

/**
 * For checks whose answer comes from `php.ini` and is only true of the PHP serving the site.
 *
 * `ini_get()` reports the configuration of the process that calls it. From the control panel that
 * is the web SAPI, and the numbers describe what visitors get. From `php craft microscope/scan` —
 * or from a queue worker started on the command line, which is how scheduled scans usually run —
 * it is the CLI binary, and on Herd, Valet, DDEV, Docker and most shared hosting that binary loads
 * a different ini file entirely.
 *
 * The failure mode is not a missing result, it is a confident wrong one: a site with
 * `memory_limit=1280M` for web requests gets told its memory limit is 128 MB because that is what
 * the console defaults to. Skipping is the honest answer. A skipped check is recorded with its
 * reason and left out of the score, the same way an unreadable `innodb_buffer_pool_size` is —
 * Microscope hasn't measured a bad value here, it has failed to measure anything.
 *
 * Checks that only *partly* depend on ini values shouldn't use this; they should gate the
 * affected findings on {@see \justinholtweb\microscope\probes\PhpProbe::isWebSapi()} and report
 * the rest, which is what {@see php\OpcacheCheck} does.
 */
trait RequiresWebSapi
{
    public function isAvailable(CheckContext $context): bool
    {
        return $context->php->isWebSapi();
    }

    public function unavailableReason(CheckContext $context): string
    {
        return Craft::t('microscope', 'This scan ran under the {sapi} SAPI, which loads its own php.ini. Any value read here would describe the console binary rather than the PHP serving the site, so Microscope hasn’t reported one. Run a scan from the control panel to check this.', [
            'sapi' => $context->php->sapi(),
        ]);
    }
}
