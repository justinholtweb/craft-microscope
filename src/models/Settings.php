<?php

namespace justinholtweb\microscope\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Microscope's plugin settings.
 *
 * Nothing here is marked `required`. A `required` rule on any one setting makes
 * `savePluginSettings()` fail wholesale, so on a fresh install *no* setting can be saved until
 * that one is filled in — values are validated for correctness only when they're present.
 *
 * Checks are opted *out* of rather than into: `disabledChecks` starts empty, so a check added in a
 * later release starts working on upgrade instead of sitting silently disabled because it wasn't
 * in a list written before it existed.
 */
class Settings extends Model
{
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public const NOTIFY_ALWAYS = 'always';
    public const NOTIFY_PROBLEMS = 'problems';
    public const NOTIFY_NEW = 'new';

    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_WEB = 'web';

    // ---------------------------------------------------------------------
    // What gets checked
    // ---------------------------------------------------------------------

    /**
     * @var string[] Handles of checks that should never run.
     */
    public array $disabledChecks = [];

    /**
     * @var bool Whether to read the site's Twig templates.
     */
    public bool $scanTemplates = true;

    /**
     * @var string[] Extra directories to scan, relative to the Craft base path. The site's own
     *               template root is always included.
     */
    public array $templateDirectories = [];

    /**
     * @var int A ceiling on how many templates one scan will read, so a site with a vendored
     *          template library can't turn a scan into a filesystem crawl.
     */
    public int $maxTemplates = 1500;

    /**
     * @var bool Whether the server category may run shell commands.
     *
     * On by default, because the alternative is having nothing to say about the hardware. Sites
     * with a security policy against `shell_exec` can turn it off explicitly rather than relying
     * on the host to refuse — and the server checks then skip rather than half-report.
     */
    public bool $allowShellProbes = true;

    // ---------------------------------------------------------------------
    // Scheduling
    // ---------------------------------------------------------------------

    public bool $scheduleEnabled = false;

    /**
     * @var string One of the FREQUENCY_* constants.
     */
    public string $scheduleFrequency = self::FREQUENCY_WEEKLY;

    /**
     * @var int Hour of day, 0–23, in the system timezone.
     */
    public int $scheduleHour = 3;

    /**
     * @var int Day of week for weekly scans: 1 (Monday) – 7 (Sunday), matching ISO-8601.
     */
    public int $scheduleWeekday = 1;

    /**
     * @var int Day of month for monthly scans, 1–28.
     *
     * Capped at 28 deliberately: "the 31st" silently skips February and two other months a year,
     * which is a bug report waiting to happen.
     */
    public int $scheduleDayOfMonth = 1;

    /**
     * @var string How due scans get started — `cron` (a scheduled console command) or `web` (a
     *             piggyback on control panel requests).
     */
    public string $scheduleTrigger = self::TRIGGER_CRON;

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    public bool $notifyEnabled = false;

    /**
     * @var string[] Email addresses to notify. Supports environment variables.
     */
    public array $notifyRecipients = [];

    /**
     * @var string When to send: every scan, any scan with problems, or only when something is new
     *             or has got worse.
     */
    public string $notifyOn = self::NOTIFY_NEW;

    /**
     * @var bool Whether to attach the report as a PDF.
     */
    public bool $attachPdf = true;

    // ---------------------------------------------------------------------
    // Housekeeping
    // ---------------------------------------------------------------------

    /**
     * @var int How many scans to keep. Older ones are pruned after each scan.
     */
    public int $retainScans = 50;

    /**
     * @var string Minimum level for Microscope's own log file.
     */
    public string $logLevel = 'warning';

    public function rules(): array
    {
        return [
            [['maxTemplates'], 'integer', 'min' => 1, 'max' => 100000],
            [['retainScans'], 'integer', 'min' => 1, 'max' => 1000],
            [['scheduleHour'], 'integer', 'min' => 0, 'max' => 23],
            [['scheduleWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['scheduleDayOfMonth'], 'integer', 'min' => 1, 'max' => 28],
            [
                ['scheduleFrequency'],
                'in',
                'range' => [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY],
            ],
            [['notifyOn'], 'in', 'range' => [self::NOTIFY_ALWAYS, self::NOTIFY_PROBLEMS, self::NOTIFY_NEW]],
            [['scheduleTrigger'], 'in', 'range' => [self::TRIGGER_CRON, self::TRIGGER_WEB]],
            [['notifyRecipients'], 'validateRecipients'],
        ];
    }

    /**
     * Recipients are validated only when notifications are actually on, and only for entries that
     * aren't environment variables — `$SCAN_REPORT_EMAIL` is a perfectly good value that no email
     * validator will accept.
     */
    public function validateRecipients(string $attribute): void
    {
        if (!$this->notifyEnabled) {
            return;
        }

        foreach ($this->notifyRecipients as $recipient) {
            $recipient = trim((string)$recipient);

            if ($recipient === '' || str_starts_with($recipient, '$')) {
                continue;
            }

            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, Craft::t('microscope', '“{value}” is not a valid email address.', [
                    'value' => $recipient,
                ]));
            }
        }
    }

    /**
     * Recipients with any environment variables resolved, and blanks dropped.
     *
     * @return string[]
     */
    public function resolvedRecipients(): array
    {
        $resolved = [];

        foreach ($this->notifyRecipients as $recipient) {
            $value = trim((string)App::parseEnv($recipient));

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $resolved[] = $value;
            }
        }

        return array_values(array_unique($resolved));
    }

    public function checkIsEnabled(string $handle): bool
    {
        return !in_array($handle, $this->disabledChecks, true);
    }
}
