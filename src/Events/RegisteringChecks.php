<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Events;

use JustinHoltWeb\Microscope\Checks\CheckInterface;

/**
 * Dispatched once per request, when Microscope assembles its catalogue of checks. Add your own, or
 * remove one, with a listener:
 *
 * ```php
 * Event::listen(RegisteringChecks::class, function (RegisteringChecks $event) {
 *     $event->checks[] = new MyCheck;
 * });
 * ```
 */
class RegisteringChecks
{
    /**
     * @param  list<CheckInterface>  $checks  In the order they run and are reported.
     */
    public function __construct(
        public array $checks,
    ) {}
}
