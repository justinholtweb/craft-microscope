<?php

namespace justinholtweb\microscope\events;

use justinholtweb\microscope\checks\CheckInterface;
use yii\base\Event;

/**
 * Fired so other plugins and modules can add their own checks.
 *
 * A plugin that ships a demanding feature — a search integration, a commerce catalogue — knows
 * better than Microscope does what "misconfigured" looks like for it, and its findings belong in
 * the same report rather than in a second one.
 */
class RegisterChecksEvent extends Event
{
    /**
     * @var CheckInterface[]
     */
    public array $checks = [];
}
