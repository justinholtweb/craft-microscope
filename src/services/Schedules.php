<?php

namespace justinholtweb\microscope\services;

use Craft;
use DateTime;
use justinholtweb\microscope\jobs\ScanJob;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanConfig;
use justinholtweb\microscope\models\Settings;
use justinholtweb\microscope\Plugin;
use justinholtweb\microscope\schedule\Cadence;
use Throwable;
use yii\base\Component;

/**
 * Deciding when a scheduled scan should run, and notifying about the result.
 *
 * Craft has no scheduler, so "when" is answered on demand: something asks whether a scan is due,
 * and this works it out from the time of the last scheduled one. Two things can ask —
 *
 * - **cron**, running `php craft microscope/scan/scheduled`, which is the right way, or
 * - **control panel requests**, which piggyback on traffic the site is getting anyway. That is the
 *   only option on hosting without cron, and it is guarded by a lock so that ten simultaneous
 *   requests don't start ten scans.
 */
class Schedules extends Component
{
    private const LOCK_KEY = 'microscope:scheduling';

    /**
     * Cache key recording that a scan has been queued but hasn't run yet.
     *
     * The mutex alone isn't enough. `isDue()` is answered from the *scans table*, and a queued job
     * doesn't write a row until it executes — so between queueing and running, every request still
     * sees a due scan. Without this flag, a site whose queue is slow would stack up one scheduled
     * scan per control panel request.
     */
    private const QUEUED_KEY = 'microscope:scheduledScanQueued';

    /**
     * How long that flag survives. Long enough for any reasonable queue to get to the job, short
     * enough that a queue which never runs doesn't suppress scheduling forever.
     */
    private const QUEUED_TTL = 7200;

    public function cadence(): Cadence
    {
        return Cadence::fromSettings(Plugin::getInstance()->getSettings());
    }

    public function lastRunAt(): ?DateTime
    {
        return Plugin::getInstance()->scans->getLastRunAt(ScanConfig::TRIGGER_SCHEDULED);
    }

    public function nextRunAt(): ?DateTime
    {
        if (!Plugin::getInstance()->getSettings()->scheduleEnabled) {
            return null;
        }

        $from = $this->lastRunAt() ?? new DateTime();
        $next = $this->cadence()->nextRunAfter($from);

        return DateTime::createFromInterface($next);
    }

    /**
     * Whether a scheduled scan is due right now.
     */
    public function isDue(?DateTime $now = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->scheduleEnabled) {
            return false;
        }

        // A scan already waiting on the queue counts as "handled", not as "still due".
        if (Craft::$app->getCache()->get(self::QUEUED_KEY) !== false) {
            return false;
        }

        return $this->cadence()->isDue($this->lastRunAt(), $now ?? new DateTime());
    }

    /**
     * Starts a scheduled scan if one is due.
     *
     * Returns whether a scan was queued. Takes a mutex first, so that concurrent callers — several
     * control panel requests arriving at once, or a cron run overlapping a web trigger — can't
     * each start their own.
     */
    public function runIfDue(?DateTime $now = null): bool
    {
        if (!$this->isDue($now)) {
            return false;
        }

        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::LOCK_KEY, 0)) {
            return false;
        }

        try {
            // Re-read the queued flag inside the lock. Another process may have queued a scan
            // between the check above and the lock being taken, which is precisely the race the
            // lock exists for — the flag, not the schedule, is what changes in that window.
            if (Craft::$app->getCache()->get(self::QUEUED_KEY) !== false) {
                return false;
            }

            $this->queue();

            return true;
        } finally {
            $mutex->release(self::LOCK_KEY);
        }
    }

    /**
     * Pushes a scheduled scan onto the queue.
     */
    public function queue(): void
    {
        $config = new ScanConfig();
        $config->trigger = ScanConfig::TRIGGER_SCHEDULED;

        Craft::$app->getQueue()->push(new ScanJob([
            'config' => $config->toArray(),
            'notify' => true,
        ]));

        Craft::$app->getCache()->set(self::QUEUED_KEY, time(), self::QUEUED_TTL);

        Craft::info('Queued a scheduled scan.', Plugin::LOG_CATEGORY);
    }

    /**
     * Clears the "queued" flag. Called by the job once it has actually run, so that a scan
     * finishing early doesn't leave the next one suppressed for two hours.
     */
    public function markQueueDrained(): void
    {
        Craft::$app->getCache()->delete(self::QUEUED_KEY);
    }

    /**
     * Whether control panel requests should be checking for due scans.
     */
    public function usesWebTrigger(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->scheduleEnabled && $settings->scheduleTrigger === Settings::TRIGGER_WEB;
    }

    /**
     * Sends the notification for a finished scan, if the settings call for one.
     *
     * @return bool Whether an email was sent.
     */
    public function notify(Scan $scan): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->notifyEnabled) {
            return false;
        }

        $recipients = $settings->resolvedRecipients();

        if ($recipients === []) {
            Craft::warning(
                'Notifications are on but no valid recipients are configured.',
                Plugin::LOG_CATEGORY,
            );

            return false;
        }

        if (!$this->shouldNotify($scan, $settings)) {
            return false;
        }

        try {
            return $plugin->reports->email($scan, $recipients, $this->subjectFor($scan, $settings));
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send the scan notification: %s', $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            return false;
        }
    }

    /**
     * Whether this particular scan warrants an email.
     */
    private function shouldNotify(Scan $scan, Settings $settings): bool
    {
        return match ($settings->notifyOn) {
            Settings::NOTIFY_ALWAYS => true,
            Settings::NOTIFY_PROBLEMS => $scan->problemCount() > 0,
            // The default, and the one that keeps the emails worth opening: only say something
            // when something has actually changed for the worse.
            default => $this->hasNewProblems($scan),
        };
    }

    private function hasNewProblems(Scan $scan): bool
    {
        $plugin = Plugin::getInstance();
        $previous = $scan->id !== null ? $plugin->scans->getPreviousScan($scan->id) : null;

        // Nothing to compare against: notify, so the first scheduled scan is never silent.
        if ($previous === null) {
            return $scan->problemCount() > 0;
        }

        $result = $scan->result ?? $plugin->scans->getResultForScan((int)$scan->id);
        $previousResult = $plugin->scans->getResultForScan((int)$previous->id);

        return $result->newSince($previousResult) !== [];
    }

    private function subjectFor(Scan $scan, Settings $settings): string
    {
        $siteName = Craft::$app->getSites()->getPrimarySite()->getName();
        $plugin = Plugin::getInstance();
        $previous = $scan->id !== null ? $plugin->scans->getPreviousScan($scan->id) : null;

        if ($previous !== null && $previous->score !== null && $scan->score !== null) {
            $delta = $scan->score - $previous->score;

            if ($delta < 0) {
                return Craft::t('microscope', '{site}: performance score dropped to {score}/100', [
                    'site' => $siteName,
                    'score' => $scan->score,
                ]);
            }

            if ($delta > 0) {
                return Craft::t('microscope', '{site}: performance score improved to {score}/100', [
                    'site' => $siteName,
                    'score' => $scan->score,
                ]);
            }
        }

        return Craft::t('microscope', '{site} performance report — score {score}/100', [
            'site' => $siteName,
            'score' => $scan->score ?? 0,
        ]);
    }
}
