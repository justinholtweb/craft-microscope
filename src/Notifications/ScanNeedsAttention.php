<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Notifications;

use CraftCms\Cms\Cp\Notifications\CpNotification;
use JustinHoltWeb\Microscope\Models\Scan;

use function CraftCms\Cms\t;

/**
 * A scheduled scan finished with something worth looking at, in the control panel's notifications.
 * Email goes separately, to Microscope's own recipient list.
 */
class ScanNeedsAttention extends CpNotification
{
    public function __construct(Scan $scan, string $headline)
    {
        parent::__construct($headline);

        $this
            ->kind('microscope-scan')
            ->title(t('Performance score {score}/100', ['score' => $scan->score ?? 0], category: 'microscope'))
            ->icon('microscope')
            ->url($scan->cpUrl());
    }
}
