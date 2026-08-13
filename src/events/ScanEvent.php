<?php

namespace justinholtweb\microscope\events;

use craft\events\CancelableEvent;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\models\ScanResult;

/**
 * Fired before and after a scan runs.
 *
 * `EVENT_BEFORE_SCAN` can be cancelled by setting `$isValid = false` — useful for suppressing
 * scheduled scans during a deploy window, when every finding would describe a site mid-deploy.
 * (That `isValid` property is what {@see CancelableEvent} exists to provide.)
 */
class ScanEvent extends CancelableEvent
{
    public ScanConfig $config;

    /**
     * @var ScanResult|null Only set on the after-scan event.
     */
    public ?ScanResult $result = null;

    /**
     * @var int|null The saved scan's ID, once there is one.
     */
    public ?int $scanId = null;
}
