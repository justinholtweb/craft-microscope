<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Events;

use JustinHoltWeb\Microscope\Models\Scan;
use Throwable;

/**
 * Dispatched when a scan throws before it could be stored. The scan row is marked failed first.
 */
class ScanFailed
{
    public function __construct(
        public Scan $scan,
        public Throwable $exception,
    ) {}
}
