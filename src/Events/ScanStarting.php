<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Events;

use JustinHoltWeb\Microscope\Data\ScanConfig;

/**
 * Dispatched before a scan runs. Set `$isValid` to false to cancel it, or change `$config`.
 *
 * ```php
 * Event::listen(ScanStarting::class, fn (ScanStarting $e) => $e->config->skipChecks[] = 'diskSpace');
 * ```
 */
class ScanStarting
{
    public bool $isValid = true;

    public function __construct(
        public ScanConfig $config,
    ) {}
}
