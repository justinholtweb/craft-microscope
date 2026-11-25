<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\TemplateSources;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The catalogue of checks, and turning them on and off.
 */
class ChecksController
{
    public function index(Checks $checks, Settings $settings, TemplateSources $templates): CpScreenResponse
    {
        $context = CheckContext::make($settings, new ScanConfig);

        // Whether each check *could* run here is worth showing beside whether it's on: "on, but
        // your host won't let it read /proc" is different from "you turned it off".
        $availability = [];

        foreach ($checks->all() as $check) {
            try {
                $available = $check->isAvailable($context);
                $reason = $available ? null : $check->unavailableReason($context);
            } catch (Throwable $e) {
                $available = false;
                $reason = $e->getMessage();
            }

            $availability[$check->handle()] = ['available' => $available, 'reason' => $reason];
        }

        return new CpScreenResponse()
            ->title(t('Checks', category: 'microscope'))
            ->selectedSubnavItem('checks')
            ->contentTemplate('microscope/checks/_index', [
                'grouped' => $checks->grouped(),
                'settings' => $settings,
                'availability' => $availability,
                'categories' => Category::all(),
                'analyzer' => $templates->analyzer(),
                'canManage' => (bool) currentUser()?->can(Plugin::PERMISSION_MANAGE),
            ]);
    }

    public function toggle(Request $request, Checks $checks): JsonResponse
    {
        $handle = (string) $request->input('handle', '');
        $enabled = $request->boolean('enabled');

        if ($checks->get($handle) === null) {
            return response()->json(['message' => t('Unknown check.', category: 'microscope')], 400);
        }

        if (! $checks->setEnabled($handle, $enabled)) {
            return response()->json(['message' => t('Couldn’t save that.', category: 'microscope')], 400);
        }

        return response()->json([
            'enabled' => $enabled,
            'message' => $enabled ? t('Check enabled.', category: 'microscope') : t('Check disabled.', category: 'microscope'),
        ]);
    }
}
