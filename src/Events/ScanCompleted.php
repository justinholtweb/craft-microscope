<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Events;

use JustinHoltWeb\Microscope\Data\ScanResult;
use JustinHoltWeb\Microscope\Models\Scan;

/**
 * Dispatched once a scan has been stored, whatever started it. Microscope's own webhook and
 * scheduled-scan notices listen for this; so can anything else (`Event::listen(ScanCompleted::class, …)`).
 */
class ScanCompleted
{
    public function __construct(
        public Scan $scan,
        public ScanResult $result,
        public ?Scan $previous = null,
    ) {}
}
