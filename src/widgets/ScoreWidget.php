<?php

namespace justinholtweb\microscope\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\Plugin;
use Throwable;

/**
 * A control panel dashboard widget showing the current score and what's outstanding.
 */
class ScoreWidget extends Widget
{
    /**
     * @var int How many findings to list.
     */
    public int $limit = 5;

    /**
     * @var bool Whether to show the per-category breakdown as well as the overall score.
     */
    public bool $showCategories = true;

    public static function displayName(): string
    {
        return Craft::t('microscope', 'Performance score');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/microscope/icon-mask.svg');
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }

    public function getTitle(): ?string
    {
        return Craft::t('microscope', 'Performance');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('microscope/widgets/settings', [
            'widget' => $this,
        ]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['limit'], 'integer', 'min' => 1, 'max' => 20],
        ]);
    }

    public function getBodyHtml(): ?string
    {
        // Widgets render on the main dashboard, which every user with control panel access sees.
        // Without this, someone with no Microscope permission would still see the site's problems
        // laid out for them.
        if (!Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_VIEW)) {
            return null;
        }

        $plugin = Plugin::getInstance();

        try {
            $scan = $plugin->scans->getLatestScan();
        } catch (Throwable) {
            // Immediately after install the tables exist but nothing has run; and during an
            // interrupted install they may not exist at all.
            $scan = null;
        }

        if ($scan === null) {
            return Craft::$app->getView()->renderTemplate('microscope/widgets/empty');
        }

        $result = $plugin->scans->getResultForScan((int)$scan->id);

        return Craft::$app->getView()->renderTemplate('microscope/widgets/score', [
            'widget' => $this,
            'scan' => $scan,
            'result' => $result,
            'findings' => array_slice($result->problems(), 0, $this->limit),
            'categories' => Category::all(),
        ]);
    }
}
