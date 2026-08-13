<?php

namespace justinholtweb\microscope\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\microscope\models\Settings;
use justinholtweb\microscope\Plugin;
use yii\web\Response;

/**
 * Microscope's settings screen.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('microscope/settings/index', [
            'title' => Craft::t('microscope', 'Microscope settings'),
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'schedules' => $plugin->schedules,
            'templateDirectories' => $plugin->templates->directories(),
            'pdfAvailable' => $plugin->reports->pdfIsAvailable(),
            'rootPath' => $this->rootPath(),
            'selectedSubnavItem' => 'settings',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $params = $this->request->getBodyParam('settings', []);

        // Checkboxes that aren't ticked aren't posted at all, so every boolean has to be read
        // rather than merged — otherwise turning one off never takes effect.
        foreach ([
            'scanTemplates',
            'allowShellProbes',
            'scheduleEnabled',
            'notifyEnabled',
            'attachPdf',
        ] as $flag) {
            $settings->$flag = (bool)($params[$flag] ?? false);
        }

        foreach ([
            'scheduleFrequency',
            'scheduleTrigger',
            'notifyOn',
            'logLevel',
        ] as $attribute) {
            if (isset($params[$attribute])) {
                $settings->$attribute = (string)$params[$attribute];
            }
        }

        foreach ([
            'maxTemplates',
            'retainScans',
            'scheduleHour',
            'scheduleWeekday',
            'scheduleDayOfMonth',
        ] as $attribute) {
            if (isset($params[$attribute])) {
                $settings->$attribute = (int)$params[$attribute];
            }
        }

        $settings->notifyRecipients = $this->parseList($params['notifyRecipients'] ?? '');
        $settings->templateDirectories = $this->parseList($params['templateDirectories'] ?? '');

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('microscope', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('microscope', 'Couldn’t save settings.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('microscope', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * The site's root directory, for the copy-and-paste cron command.
     *
     * Craft has no `rootPath()` accessor, so this derives it from the config path — `@root` is
     * defined by the standard bootstrap but a custom one may not set it.
     */
    private function rootPath(): string
    {
        $alias = Craft::getAlias('@root', false);

        if (is_string($alias) && $alias !== '') {
            return $alias;
        }

        return dirname(Craft::$app->getPath()->getConfigPath());
    }

    /**
     * Splits a textarea into a trimmed list, accepting either newlines or commas.
     *
     * @return string[]
     */
    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string)$value) ?: [];
        }

        return array_values(array_filter(array_map('trim', $items), fn(string $item) => $item !== ''));
    }
}
