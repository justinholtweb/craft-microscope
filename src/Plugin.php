<?php

namespace justinholtweb\microscope;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\microscope\models\Settings;
use justinholtweb\microscope\services\Checks;
use justinholtweb\microscope\services\Reports;
use justinholtweb\microscope\services\Scans;
use justinholtweb\microscope\services\Schedules;
use justinholtweb\microscope\services\Templates;
use justinholtweb\microscope\variables\MicroscopeVariable;
use justinholtweb\microscope\widgets\ScoreWidget;
use Throwable;
use yii\base\Event;

/**
 * Microscope — put a Craft site under the microscope.
 *
 * @property-read Checks $checks
 * @property-read Scans $scans
 * @property-read Reports $reports
 * @property-read Schedules $schedules
 * @property-read Templates $templates
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** See the dashboard, scans and reports. */
    public const PERMISSION_VIEW = 'microscope:view';

    /** Start a scan. */
    public const PERMISSION_SCAN = 'microscope:scan';

    /** Change which checks run, and the schedule. */
    public const PERMISSION_MANAGE = 'microscope:manage';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'microscope';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'checks' => Checks::class,
                'scans' => Scans::class,
                'reports' => Reports::class,
                'schedules' => Schedules::class,
                'templates' => Templates::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
        $this->registerWidgets();
        $this->registerTwigVariable();
        $this->registerScheduleTrigger();
    }

    /**
     * Sends Microscope's log entries to storage/logs/microscope.log.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    /**
     * Exposes `craft.microscope` to templates.
     */
    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('microscope', MicroscopeVariable::class);
            },
        );
    }

    private function registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ScoreWidget::class;
            },
        );
    }

    /**
     * Lets control panel traffic start a due scheduled scan, where the site has opted into that.
     *
     * Craft has no scheduler, and plenty of hosting has no usable cron either, so this is the
     * fallback: check after the response has been sent, so the visitor never waits for it, and
     * only on control panel requests, so a public page can't be made to do it.
     *
     * The check itself is a cache read in the overwhelmingly common case where nothing is due —
     * {@see Schedules::isDue()} short-circuits on a queued-scan flag before it touches the
     * database — and anything it does find gets pushed onto the queue rather than run inline.
     */
    private function registerScheduleTrigger(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Event::on(
            Application::class,
            Application::EVENT_AFTER_REQUEST,
            function() {
                try {
                    if (!Craft::$app->getRequest()->getIsCpRequest()) {
                        return;
                    }

                    if (!$this->schedules->usesWebTrigger()) {
                        return;
                    }

                    $this->schedules->runIfDue();
                } catch (Throwable $e) {
                    // This runs after the response has gone out, so there is nothing useful to
                    // show a user and nothing to gain from letting it surface. Log and move on.
                    Craft::warning(
                        sprintf('The scheduled-scan check failed: %s', $e->getMessage()),
                        self::LOG_CATEGORY,
                    );
                }
            },
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'dashboard' => ['label' => Craft::t('microscope', 'Dashboard'), 'url' => 'microscope'],
        ];

        if ($user->checkPermission(self::PERMISSION_VIEW)) {
            $subnav['scans'] = ['label' => Craft::t('microscope', 'Scans'), 'url' => 'microscope/scans'];
            $subnav['checks'] = ['label' => Craft::t('microscope', 'Checks'), 'url' => 'microscope/checks'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('microscope', 'Settings'), 'url' => 'microscope/settings'];
        }

        $item['subnav'] = $subnav;

        // A badge for outstanding critical findings, so the nav reflects the site's state without
        // anybody having to go and look.
        try {
            $latest = $this->scans->getLatestScan();

            if ($latest !== null && $latest->criticalCount > 0) {
                $item['badgeCount'] = $latest->criticalCount;
            }
        } catch (Throwable) {
            // The nav is built on every CP request, including ones during an install where the
            // tables don't exist yet. A missing badge is not worth an error page.
        }

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('microscope/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['microscope'] = 'microscope/dashboard/index';
                $event->rules['microscope/scans'] = 'microscope/scans/index';
                $event->rules['microscope/scans/<scanId:\d+>'] = 'microscope/scans/detail';
                $event->rules['microscope/scans/<scanId:\d+>/report'] = 'microscope/reports/print';
                $event->rules['microscope/scans/<scanId:\d+>/report.pdf'] = 'microscope/reports/pdf';
                $event->rules['microscope/checks'] = 'microscope/checks/index';
                $event->rules['microscope/settings'] = 'microscope/settings/index';
            },
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('microscope', 'Microscope'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('microscope', 'View scans and reports'),
                            'nested' => [
                                self::PERMISSION_SCAN => [
                                    'label' => Craft::t('microscope', 'Run scans'),
                                ],
                                self::PERMISSION_MANAGE => [
                                    'label' => Craft::t('microscope', 'Manage checks and the schedule'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }
}
