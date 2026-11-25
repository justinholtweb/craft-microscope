<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Widgets;

use CraftCms\Cms\Dashboard\Widgets\Widget;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Services\Scans;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * "Performance" on Craft's dashboard: the latest score, the per-area bars and the top problems.
 * Only for people who can view Microscope: otherwise anyone with control panel access would see
 * the site's problems laid out for them.
 */
class ScoreWidget extends Widget
{
    public int $limit = 5;

    public bool $showCategories = true;

    #[Override]
    public static function displayName(): string
    {
        return t('Performance score', category: 'microscope');
    }

    #[Override]
    public static function icon(): string
    {
        return 'microscope';
    }

    #[Override]
    public static function isSelectable(): bool
    {
        return (bool) currentUser()?->can(Plugin::PERMISSION_VIEW);
    }

    #[Override]
    public function getRules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:20'],
            'showCategories' => ['boolean'],
        ];
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Findings to show', category: 'microscope'))->required()->control(Number::make('limit')->value($this->limit)->min(1)->max(20)->size(2)),
            Field::make(t('Show the score for each area', category: 'microscope'))->control(Lightswitch::make('showCategories')->value($this->showCategories)),
        ]);
    }

    public function component(): ?string
    {
        return 'craft:html-widget';
    }

    /** @return array{html: string} */
    public function props(): array
    {
        if (! currentUser()?->can(Plugin::PERMISSION_VIEW)) {
            return ['html' => ''];
        }

        try {
            $scans = app(Scans::class);
            $scan = $scans->getLatestScan();
        } catch (Throwable) {
            // During an interrupted install the tables may not exist yet.
            $scan = null;
        }

        if ($scan === null) {
            return ['html' => template('microscope/widgets/_empty', [], TemplateMode::Cp)];
        }

        $result = $scans->getResultForScan($scan);

        return ['html' => template('microscope/widgets/_score', [
            'widget' => $this,
            'scan' => $scan,
            'result' => $result,
            'findings' => array_slice($result->problems(), 0, $this->limit),
            'categories' => Category::all(),
        ], TemplateMode::Cp)];
    }
}
