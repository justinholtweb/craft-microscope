<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons as CpIcons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Microscope\Console\Commands\ChecksListCommand;
use JustinHoltWeb\Microscope\Console\Commands\ScanListCommand;
use JustinHoltWeb\Microscope\Console\Commands\ScanPruneCommand;
use JustinHoltWeb\Microscope\Console\Commands\ScanReportCommand;
use JustinHoltWeb\Microscope\Console\Commands\ScanRunCommand;
use JustinHoltWeb\Microscope\Console\Commands\ScanScheduledCommand;
use JustinHoltWeb\Microscope\Events\ScanCompleted;
use JustinHoltWeb\Microscope\Gql\GqlAccess;
use JustinHoltWeb\Microscope\Gql\Queries\MicroscopeQueries;
use JustinHoltWeb\Microscope\Listeners\QueueScanWebhook;
use JustinHoltWeb\Microscope\Mcp\MicroscopeTools;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Notifier;
use JustinHoltWeb\Microscope\Services\Reports;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Services\Schedules;
use JustinHoltWeb\Microscope\Services\TemplateSources;
use JustinHoltWeb\Microscope\Support\MicroscopeVariable;
use JustinHoltWeb\Microscope\Widgets\ScoreWidget;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Microscope — put a Craft site under the microscope.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** See the dashboard, scans and reports. */
    public const string PERMISSION_VIEW = 'microscope:view';

    /** Start a scan. */
    public const string PERMISSION_SCAN = 'microscope:scan';

    /** Change which checks run, delete scans, email reports. */
    public const string PERMISSION_MANAGE = 'microscope:manage';

    /** The Laravel log channel Microscope writes to (`storage/logs/microscope-*.log` unless the site defines its own). */
    public const string LOG_CHANNEL = 'microscope';

    /** Unchanged from 5.x: the tables are the same. */
    public string $schemaVersion = '1.0.0';

    /**
     * Sites upgrade from the last Microscope 5 release, so `database/migrations/Install.php` can
     * describe the final schema.
     */
    public string $minVersionRequired = '5.0.3';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    protected array $styles = [
        __DIR__.'/../resources/css/microscope.css' => 'css/microscope.css',
    ];

    protected array $scripts = [
        __DIR__.'/../resources/js/microscope.js' => 'js/microscope.js',
    ];

    protected array $commands = [
        ScanRunCommand::class,
        ScanScheduledCommand::class,
        ScanListCommand::class,
        ScanReportCommand::class,
        ScanPruneCommand::class,
        ChecksListCommand::class,
    ];

    protected array $widgets = [
        ScoreWidget::class,
    ];

    protected array $gqlQueries = [
        MicroscopeQueries::class,
    ];

    protected array $events = [
        ScanCompleted::class => QueueScanWebhook::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        // One instance of each per request: the check catalogue is assembled once, and the
        // services share one settings object.
        foreach ([Checks::class, Scans::class, Schedules::class, Reports::class, Notifier::class, TemplateSources::class, MicroscopeVariable::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(CapabilityRegistry $capabilities): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());

        $this->registerLogChannel();

        // MCP: the tools are on Craft's own server (`php artisan craft:mcp:serve`, or HTTP).
        $capabilities->register(MicroscopeTools::class);

        Event::listen(GqlSchemaComponentsResolving::class, function (GqlSchemaComponentsResolving $event): void {
            $event->queries[t('Microscope', category: 'microscope')] = [
                GqlAccess::SCOPE.':read' => ['label' => t('Query Microscope scans and findings (microscopeScans, microscopeScan, microscopeChecks). Never available on the public schema.', category: 'microscope')],
            ];
        });

        // Templates: `microscope.score()` (Twig), `$microscope->score()` (Blade), and
        // `craft.microscope.score()` as in Microscope 5.
        Event::listen(TemplateGlobalsResolving::class, function (TemplateGlobalsResolving $event): void {
            $event->globals['microscope'] = app(MicroscopeVariable::class);
        });
        CraftVariable::macro('microscope', fn () => app(MicroscopeVariable::class));

        // Hosting with no scheduler: after a control panel response has gone out, see whether a
        // scheduled scan is due. One cache read a minute at most; the scan itself is queued.
        if (! $this->app->runningInConsole()) {
            $this->app->terminating(function (): void {
                try {
                    if (request()->isCpRequest()) {
                        app(Schedules::class)->runIfDueFromWeb();
                    }
                } catch (Throwable $e) {
                    Log::channel(self::LOG_CHANNEL)->warning('The scheduled-scan check failed: '.$e->getMessage());
                }
            });
        }
    }

    /**
     * A `microscope` log channel, unless the site defines its own in `config/logging.php` (to send
     * Microscope's messages to Slack, Papertrail or a stack, say).
     */
    private function registerLogChannel(): void
    {
        if (config('logging.channels.'.self::LOG_CHANNEL) !== null) {
            return;
        }

        config(['logging.channels.'.self::LOG_CHANNEL => [
            'driver' => 'daily',
            'path' => storage_path('logs/microscope.log'),
            'level' => $this->getSettings()->logLevel,
            'days' => 10,
            'replace_placeholders' => true,
        ]]);
    }

    /**
     * Scheduled scans on Laravel's scheduler (`php artisan schedule:run` from cron, or
     * `schedule:work`). The command decides whether a scan is due, so hourly suits every cadence
     * the settings allow.
     */
    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(ScanScheduledCommand::class)
            ->hourly()
            ->withoutOverlapping(120)
            ->when(fn () => $this->getSettings()->scheduleEnabled && $this->getSettings()->scheduleTrigger === Settings::TRIGGER_CRON)
            ->name('microscope:scan:scheduled');
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_VIEW,
                label: t('View scans and reports', category: 'microscope'),
                nested: collect([
                    new Permission(key: self::PERMISSION_SCAN, label: t('Run scans', category: 'microscope')),
                    new Permission(key: self::PERMISSION_MANAGE, label: t('Manage checks and scans', category: 'microscope')),
                ]),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();
        $user = currentUser();

        if (! $item instanceof NavItem || $user === null || ! $user->can(self::PERMISSION_VIEW)) {
            return null;
        }

        $subnav = [
            new NavItem()->label(t('Dashboard', category: 'microscope'))->href('microscope'),
            new NavItem()->label(t('Scans', category: 'microscope'))->href('microscope/scans'),
            new NavItem()->label(t('Checks', category: 'microscope'))->href('microscope/checks'),
        ];

        if ($user->isAdmin()) {
            $subnav[] = new NavItem()->label(t('Settings', category: 'microscope'))->href('settings/plugins/microscope');
        }

        $item->subnav($subnav);

        // Outstanding critical findings, so the nav reflects the site's state without anybody
        // having to go and look. Never worth an error page (mid-install, before the tables exist).
        try {
            $critical = app(Scans::class)->getLatestScan()->criticalCount ?? 0;

            if ($critical > 0) {
                $item->badgeCount($critical)->badgeLabel(t('{count, plural, =1{# critical finding} other{# critical findings}}', ['count' => $critical], category: 'microscope'));
            }
        } catch (Throwable) {
        }

        return $this->customiseNavItem($item, $this->getSettings());
    }

    /**
     * The sidebar label and icon from settings: label, then pasted SVG (sanitised every time it's
     * drawn), then an icon name. Craft's own Settings → Control Panel Navigation overrides win,
     * because Craft applies them afterwards. A bad value never takes the nav down.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitised = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitised !== '') {
                    return $item->iconSvg($sanitised)->icon(null);
                }
            }

            if ($icon !== '' && IconSets::parse($icon) !== null) {
                // A custom set's icon (`set:name`) is drawn server-side.
                return $item->iconSvg(CpIcons::svg($icon))->icon(null);
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                return $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable $e) {
            Log::channel(self::LOG_CHANNEL)->warning('Microscope couldn’t draw the custom nav icon: '.$e->getMessage());
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $settings = $this->getSettings();
        $hours = array_map(fn (int $h) => ['label' => sprintf('%02d:00', $h), 'value' => (string) $h], range(0, 23));
        $weekdays = [];

        foreach ([1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $value => $day) {
            $weekdays[] = ['label' => t($day, category: 'microscope'), 'value' => (string) $value];
        }

        return Ui::make([
            Heading::make('microscope-checks', t('What gets checked', category: 'microscope'))
                ->description(t('Turn individual checks on and off on the Checks screen.', category: 'microscope')),
            Field::make(t('Scan templates', category: 'microscope'), Lightswitch::make('scanTemplates'))
                ->instructions(t('Read the site’s Twig templates for query patterns that cost a request more than they need to.', category: 'microscope')),
            Field::make(t('Extra template directories', category: 'microscope'), Textarea::make('templateDirectories')->rows(3)->monospace()->placeholder('modules/mymodule/templates')->value(implode("\n", $settings->templateDirectoryList())))
                ->instructions(t('One per line, relative to the project root. The site’s own template folder is always scanned.', category: 'microscope')),
            Field::make(t('Most templates per scan', category: 'microscope'), Number::make('maxTemplates')->min(1)->max(100000))
                ->instructions(t('A ceiling, so a site with a vendored template library can’t turn a scan into a filesystem crawl.', category: 'microscope')),
            Field::make(t('Allow shell commands', category: 'microscope'), Lightswitch::make('allowShellProbes'))
                ->instructions(t('The server checks read CPU, memory and disk with a few read-only shell commands. Turn this off where policy forbids `shell_exec`; those checks are then skipped.', category: 'microscope')),

            Heading::make('microscope-schedule', t('Scheduled scans', category: 'microscope')),
            Field::make(t('Scan on a schedule', category: 'microscope'), Lightswitch::make('scheduleEnabled')),
            Field::make(t('How often', category: 'microscope'), Choice::make('scheduleFrequency')->options([
                ['label' => t('Daily', category: 'microscope'), 'value' => Settings::FREQUENCY_DAILY],
                ['label' => t('Weekly', category: 'microscope'), 'value' => Settings::FREQUENCY_WEEKLY],
                ['label' => t('Monthly', category: 'microscope'), 'value' => Settings::FREQUENCY_MONTHLY],
            ])),
            Field::make(t('At', category: 'microscope'), Choice::make('scheduleHour')->options($hours))
                ->instructions(t('In the system timezone.', category: 'microscope')),
            Field::make(t('On (weekly)', category: 'microscope'), Choice::make('scheduleWeekday')->options($weekdays)),
            Field::make(t('On day (monthly)', category: 'microscope'), Number::make('scheduleDayOfMonth')->min(1)->max(28))
                ->instructions(t('1–28, so every month has it.', category: 'microscope')),
            Field::make(t('Started by', category: 'microscope'), Choice::make('scheduleTrigger')->options([
                ['label' => t('Laravel’s scheduler (recommended)', category: 'microscope'), 'value' => Settings::TRIGGER_CRON],
                ['label' => t('Control panel visits', category: 'microscope'), 'value' => Settings::TRIGGER_WEB],
            ]))
                ->instructions(t('The scheduler runs `microscope:scan:scheduled` hourly once `php artisan schedule:run` is in cron (or `schedule:work` is running). Without a scheduler, control panel visits can queue a due scan instead.', category: 'microscope')),

            Heading::make('microscope-notify', t('Notifications', category: 'microscope'))
                ->description(t('Sent after scheduled scans.', category: 'microscope')),
            Field::make(t('Notify after scheduled scans', category: 'microscope'), Lightswitch::make('notifyEnabled')),
            Field::make(t('When', category: 'microscope'), Choice::make('notifyOn')->options([
                ['label' => t('Only when something is new or worse', category: 'microscope'), 'value' => Settings::NOTIFY_NEW],
                ['label' => t('Whenever there are problems', category: 'microscope'), 'value' => Settings::NOTIFY_PROBLEMS],
                ['label' => t('After every scan', category: 'microscope'), 'value' => Settings::NOTIFY_ALWAYS],
            ])),
            Field::make(t('Email', category: 'microscope'), Textarea::make('notifyRecipients')->rows(3)->placeholder("ops@example.com\n\$MICROSCOPE_EMAIL")->value(implode("\n", Settings::toList($settings->notifyRecipients))))
                ->instructions(t('One address per line (or comma-separated), or an environment variable.', category: 'microscope')),
            Field::make(t('Attach the PDF report', category: 'microscope'), Lightswitch::make('attachPdf'))
                ->instructions(t('Needs dompdf (`composer require dompdf/dompdf`).', category: 'microscope')),
            Field::make(t('Slack webhook URL', category: 'microscope'), Text::make('slackWebhookUrl')->placeholder('$MICROSCOPE_SLACK_WEBHOOK'))
                ->instructions(t('A Slack incoming-webhook URL, or an environment variable. Keep the URL itself in `.env`.', category: 'microscope')),
            Field::make(t('Control panel notifications', category: 'microscope'), Lightswitch::make('notifyCpUsers'))
                ->instructions(t('Also notify everyone who can manage Microscope, in the control panel.', category: 'microscope')),

            Heading::make('microscope-webhook', t('Webhook', category: 'microscope')),
            Field::make(t('Scan webhook URL', category: 'microscope'), Text::make('webhookUrl')->placeholder('$MICROSCOPE_WEBHOOK'))
                ->instructions(t('Microscope POSTs a JSON summary here after every completed scan — the score, problems, and what’s new or resolved — for dashboards, uptime tools or CI. Sent from the queue, retried on failure.', category: 'microscope')),
            Field::make(t('Webhook secret', category: 'microscope'), Text::make('webhookSecret')->placeholder('$MICROSCOPE_WEBHOOK_SECRET'))
                ->instructions(t('Optional. Signs each body as `X-Microscope-Signature: sha256=<HMAC>`. Use an environment variable.', category: 'microscope')),

            Heading::make('microscope-nav', t('Control panel', category: 'microscope'))
                ->description(t('How Microscope appears in the control panel’s sidebar. An override set in Settings → Control Panel Navigation takes precedence.', category: 'microscope')),
            Field::make(t('Sidebar label', category: 'microscope'), Text::make('navLabel')->placeholder('Microscope'))
                ->instructions(t('Leave empty for “Microscope”.', category: 'microscope')),
            Field::make(t('Sidebar icon', category: 'microscope'), IconPicker::make('navIcon'))
                ->instructions(t('Leave empty for Microscope’s own icon.', category: 'microscope')),
            Field::make(t('Custom SVG icon', category: 'microscope'), Textarea::make('navIconSvg')->rows(4)->monospace())
                ->instructions(t('Optional. Paste one `<svg>` element to use instead of the icon above. It’s sanitised every time it’s drawn; a single-colour icon using `currentColor` matches the rest of the sidebar.', category: 'microscope')),

            Heading::make('microscope-housekeeping', t('Housekeeping', category: 'microscope')),
            Field::make(t('Scans to keep', category: 'microscope'), Number::make('retainScans')->min(1)->max(1000))
                ->instructions(t('Older scans are deleted after each new one.', category: 'microscope')),
            Field::make(t('Log level', category: 'microscope'), Choice::make('logLevel')->options(array_map(
                fn (string $level) => ['label' => ucfirst($level), 'value' => $level],
                ['debug', 'info', 'notice', 'warning', 'error'],
            )))
                ->instructions(t('For the `microscope` log channel (`storage/logs/microscope-*.log`), unless `config/logging.php` defines its own.', category: 'microscope')),
        ]);
    }
}
