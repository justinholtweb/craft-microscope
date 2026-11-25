<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Models;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;
use DOMDocument;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Microscope's settings.
 *
 * Every Microscope 5 key is unchanged, so an upgraded site keeps what it had in project config,
 * and `config/craft/microscope.php` overrides them as `config/microscope.php` did.
 *
 * Nothing is `required`: on a fresh install that would stop *every* setting saving until one was
 * filled in. Checks are opted out of (`disabledChecks`), so a check added in a later release starts
 * working on upgrade.
 *
 * Text settings are nullable, because Laravel turns a blank field into null before Craft assigns it.
 * The two list settings take a list or newline/comma-separated text (what the settings screen
 * posts) and are stored as lists, the shape Microscope 5 stored.
 */
class Settings extends PluginSettings
{
    public const string FREQUENCY_DAILY = 'daily';

    public const string FREQUENCY_WEEKLY = 'weekly';

    public const string FREQUENCY_MONTHLY = 'monthly';

    public const string NOTIFY_ALWAYS = 'always';

    public const string NOTIFY_PROBLEMS = 'problems';

    public const string NOTIFY_NEW = 'new';

    /** Due scans are started by Laravel's scheduler (`schedule:run` from cron, or `schedule:work`). */
    public const string TRIGGER_CRON = 'cron';

    /** Due scans are started after control panel requests, for hosting without a scheduler. */
    public const string TRIGGER_WEB = 'web';

    /** The largest pasted SVG icon accepted, in bytes. */
    public const int MAX_SVG_BYTES = 20000;

    // What gets checked ---------------------------------------------------------------------

    /** @var list<string> Handles of checks that should never run. */
    public array $disabledChecks = [];

    /** Whether to read the site's templates. */
    public bool $scanTemplates = true;

    /** @var list<string>|string|null Extra template directories, relative to the project root. */
    public array|string|null $templateDirectories = [];

    /** A ceiling on how many templates one scan reads. */
    public int $maxTemplates = 1500;

    /** Whether the server checks may run shell commands. */
    public bool $allowShellProbes = true;

    // Scheduling ------------------------------------------------------------------------------

    public bool $scheduleEnabled = false;

    public string $scheduleFrequency = self::FREQUENCY_WEEKLY;

    /** Hour of day, 0–23, in the system timezone. */
    public int $scheduleHour = 3;

    /** ISO-8601 day of week for weekly scans: 1 (Monday) – 7 (Sunday). */
    public int $scheduleWeekday = 1;

    /** Day of month for monthly scans, 1–28 (never a day some months don't have). */
    public int $scheduleDayOfMonth = 1;

    /** `cron` (Laravel's scheduler) or `web` (control panel requests). */
    public string $scheduleTrigger = self::TRIGGER_CRON;

    // Notifications ---------------------------------------------------------------------------

    public bool $notifyEnabled = false;

    /** @var list<string>|string|null Addresses to email. `$ENV_VAR` entries allowed. */
    public array|string|null $notifyRecipients = [];

    /** When to notify after a scheduled scan: every scan, any problems, or only new/worse ones. */
    public string $notifyOn = self::NOTIFY_NEW;

    /** Attach the report as a PDF (needs dompdf). */
    public bool $attachPdf = true;

    /** Also post the scheduled-scan notice to a Slack incoming webhook. `$ENV_VAR` allowed. */
    public ?string $slackWebhookUrl = null;

    /** Also send the notice to the control panel notifications of users who can manage Microscope. */
    public bool $notifyCpUsers = false;

    /**
     * POSTed a JSON summary of every completed scan (scheduled, manual or console), for dashboards,
     * uptime tools or CI. `$ENV_VAR` allowed.
     */
    public ?string $webhookUrl = null;

    /** Signs webhook bodies (`X-Microscope-Signature: sha256=…`, HMAC-SHA256). `$ENV_VAR` allowed. */
    public ?string $webhookSecret = null;

    // Control panel ---------------------------------------------------------------------------

    /** The CP sidebar label. Empty uses "Microscope". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: an icon name from Craft's icon picker. Empty uses Microscope's own. */
    public ?string $navIcon = null;

    /** Raw SVG for the CP sidebar icon. Wins over `navIcon`. Sanitised every time it's drawn. */
    public ?string $navIconSvg = null;

    // Housekeeping ----------------------------------------------------------------------------

    /** How many scans to keep. Older ones are pruned after each scan. */
    public int $retainScans = 50;

    /** Minimum level for Microscope's `microscope` log channel. */
    public string $logLevel = 'warning';

    public function getRules(): array
    {
        return [
            'disabledChecks' => ['array'],
            'scanTemplates' => ['boolean'],
            'maxTemplates' => ['integer', 'min:1', 'max:100000'],
            'allowShellProbes' => ['boolean'],
            'scheduleEnabled' => ['boolean'],
            'scheduleFrequency' => ['in:'.implode(',', [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY])],
            'scheduleHour' => ['integer', 'min:0', 'max:23'],
            'scheduleWeekday' => ['integer', 'min:1', 'max:7'],
            'scheduleDayOfMonth' => ['integer', 'min:1', 'max:28'],
            'scheduleTrigger' => ['in:'.self::TRIGGER_CRON.','.self::TRIGGER_WEB],
            'notifyEnabled' => ['boolean'],
            'notifyOn' => ['in:'.implode(',', [self::NOTIFY_ALWAYS, self::NOTIFY_PROBLEMS, self::NOTIFY_NEW])],
            'attachPdf' => ['boolean'],
            'slackWebhookUrl' => ['nullable', 'string', 'max:500'],
            'notifyCpUsers' => ['boolean'],
            'webhookUrl' => ['nullable', 'string', 'max:500'],
            'webhookSecret' => ['nullable', 'string', 'max:500'],
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+(:[a-z0-9\-]+)?$/'],
            'navIconSvg' => ['nullable', 'string', 'max:'.self::MAX_SVG_BYTES],
            'retainScans' => ['integer', 'min:1', 'max:1000'],
            'logLevel' => ['in:debug,info,notice,warning,error,critical,alert,emergency'],
        ];
    }

    public function getMessages(): array
    {
        return [
            'navIcon.regex' => t('Choose an icon from the icon picker.', category: 'microscope'),
        ];
    }

    /**
     * Normalises the list and text settings, then makes the checks a rule string can't express:
     * the icon must exist, the SVG must be one `<svg>`, URLs (once `$ENV_VAR`s are resolved) must be
     * http(s), and recipients must be addresses — but only while notifications are on, and never for
     * an `$ENV_VAR` entry, which no email validator would accept.
     */
    #[Override]
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        $this->notifyRecipients = self::toList($this->notifyRecipients);
        $this->templateDirectories = self::toList($this->templateDirectories);
        $this->disabledChecks = self::toList($this->disabledChecks);

        foreach (['navLabel', 'navIcon', 'navIconSvg', 'slackWebhookUrl', 'webhookUrl', 'webhookSecret'] as $attribute) {
            $value = $this->$attribute;
            $this->$attribute = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        parent::validate($attributeNames, $clearErrors, $throw);
        $errors = $this->errors();

        if ($this->navIcon !== null && ! $errors->has('navIcon') && ! self::iconExists($this->navIcon)) {
            $errors->add('navIcon', t('Choose an icon from the icon picker.', category: 'microscope'));
        }

        if ($this->navIconSvg !== null && ! $errors->has('navIconSvg') && ! self::isSvgMarkup($this->navIconSvg)) {
            $errors->add('navIconSvg', t('Paste a single <svg> element.', category: 'microscope'));
        }

        foreach (['slackWebhookUrl', 'webhookUrl'] as $attribute) {
            $url = $this->$attribute;

            if ($url !== null && ! $errors->has($attribute) && ! str_starts_with($url, '$') && ! self::isHttpUrl($url)) {
                $errors->add($attribute, t('Enter an http(s) URL, or an environment variable.', category: 'microscope'));
            }
        }

        if ($this->notifyEnabled) {
            foreach ($this->notifyRecipients as $recipient) {
                if (! str_starts_with($recipient, '$') && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    $errors->add('notifyRecipients', t('“{value}” is not a valid email address.', ['value' => $recipient], category: 'microscope'));
                }
            }
        }

        return $errors->isEmpty();
    }

    public function checkIsEnabled(string $handle): bool
    {
        return ! in_array($handle, $this->disabledChecks, true);
    }

    /**
     * Recipients with environment variables resolved, invalid and blank ones dropped.
     *
     * @return list<string>
     */
    public function resolvedRecipients(): array
    {
        $resolved = [];

        foreach (self::toList($this->notifyRecipients) as $recipient) {
            foreach (self::toList((string) Env::parse($recipient)) as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                    $resolved[] = $address;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /** @return list<string> */
    public function templateDirectoryList(): array
    {
        return self::toList($this->templateDirectories);
    }

    public function slackWebhook(): ?string
    {
        return self::resolvedUrl($this->slackWebhookUrl);
    }

    public function webhook(): ?string
    {
        return self::resolvedUrl($this->webhookUrl);
    }

    public function webhookSecretValue(): ?string
    {
        $secret = Env::parse($this->webhookSecret);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public static function iconExists(string $icon): bool
    {
        if (str_contains($icon, ':')) {
            // A custom icon set's `set:name`: checked when it's drawn.
            return true;
        }

        try {
            return is_file(Icons::resolveIconPath($icon));
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether the text is one `<svg>` element (optionally after an XML declaration or comments). */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (! preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $svg) || ! preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // No network, no entity expansion.
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }

    /**
     * A list from a list, or from newline/comma-separated text. Blank entries are dropped.
     *
     * @param  array<mixed>|string|null  $value
     * @return list<string>
     */
    public static function toList(array|string|null $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string) $value) ?: []);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $item) => trim(is_scalar($item) ? (string) $item : ''), $items),
            fn (string $item) => $item !== '',
        )));
    }

    private static function resolvedUrl(?string $value): ?string
    {
        $url = Env::parse($value);

        return is_string($url) && self::isHttpUrl($url) ? $url : null;
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
