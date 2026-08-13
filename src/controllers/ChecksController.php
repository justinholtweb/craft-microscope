<?php

namespace justinholtweb\microscope\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\Plugin;
use yii\web\Response;

/**
 * The catalogue of checks, and switching them on and off.
 */
class ChecksController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $context = $plugin->scans->buildContext();

        // Whether each check *could* run here is worth showing alongside whether it's enabled:
        // "this check is on but your host won't let it read `/proc`" is a different situation from
        // "you turned this off", and the catalogue is the natural place to learn that.
        $availability = [];

        foreach ($plugin->checks->getAllChecks() as $check) {
            $available = $check->isAvailable($context);

            $availability[$check->handle()] = [
                'available' => $available,
                'reason' => $available ? null : $check->unavailableReason($context),
            ];
        }

        return $this->renderTemplate('microscope/checks/index', [
            'title' => Craft::t('microscope', 'Checks'),
            'grouped' => $plugin->checks->getGroupedChecks(),
            'settings' => $plugin->getSettings(),
            'availability' => $availability,
            'categories' => Category::all(),
            'analyzer' => $plugin->templates->analyzer(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'selectedSubnavItem' => 'checks',
        ]);
    }

    /**
     * Toggles one check.
     */
    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $handle = (string)$this->request->getRequiredBodyParam('handle');
        $enabled = (bool)$this->request->getRequiredBodyParam('enabled');

        $plugin = Plugin::getInstance();

        if ($plugin->checks->getCheck($handle) === null) {
            return $this->asFailure(Craft::t('microscope', 'Unknown check.'));
        }

        if (!$plugin->checks->setEnabled($handle, $enabled)) {
            return $this->asFailure(Craft::t('microscope', 'Couldn’t save that.'));
        }

        return $this->asSuccess($enabled
            ? Craft::t('microscope', 'Check enabled.')
            : Craft::t('microscope', 'Check disabled.'));
    }
}
