<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Jobs\RunScan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;
use JustinHoltWeb\Microscope\Support\Cadence;

/**
 * Deciding when a scheduled scan is due, and queueing it.
 *
 * Two things can ask: Laravel's scheduler, which runs `microscope:scan:scheduled` hourly (the right
 * way: `php artisan schedule:run` from cron, or `schedule:work`), and control panel requests after
 * their response has gone out, for hosting with no scheduler at all. Either way a cache lock means
 * simultaneous callers can't each start a scan.
 */
class Schedules
{
    private const string LOCK_KEY = 'microscope:scheduling';

    /**
     * Records that a scan has been queued but hasn't run yet. `isDue()` reads the scans table, and
     * a queued job writes no row until it runs, so without this a slow queue would collect one
     * scheduled scan per caller.
     */
    private const string QUEUED_KEY = 'microscope:scheduledScanQueued';

    /** Long enough for any reasonable queue to reach the job; short enough that a dead queue doesn't stop scheduling forever. */
    private const int QUEUED_TTL = 7200;

    /** How often control panel requests may even look, so the check costs one cache read. */
    private const string WEB_THROTTLE_KEY = 'microscope:webScheduleCheck';

    public function __construct(
        private readonly Scans $scans,
        private readonly Settings $settings,
    ) {}

    public function cadence(): Cadence
    {
        return Cadence::fromSettings($this->settings);
    }

    public function lastRunAt(): ?Carbon
    {
        return $this->scans->getLastRunAt(ScanConfig::TRIGGER_SCHEDULED);
    }

    public function nextRunAt(): ?CarbonInterface
    {
        if (! $this->settings->scheduleEnabled) {
            return null;
        }

        return Carbon::instance($this->cadence()->nextRunAfter($this->lastRunAt() ?? Carbon::now()));
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        if (! $this->settings->scheduleEnabled || Cache::has(self::QUEUED_KEY)) {
            return false;
        }

        return $this->cadence()->isDue($this->lastRunAt(), $now ?? Carbon::now());
    }

    /**
     * Queues a scheduled scan if one is due. Returns whether one was queued.
     */
    public function runIfDue(?CarbonInterface $now = null): bool
    {
        if (! $this->isDue($now)) {
            return false;
        }

        $lock = Cache::lock(self::LOCK_KEY, 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            // `add` only succeeds for the first caller: whoever got here between the check above
            // and the lock finds the flag already set.
            if (! Cache::add(self::QUEUED_KEY, time(), self::QUEUED_TTL)) {
                return false;
            }

            $this->queue();

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Pushes a scheduled scan onto the queue. */
    public function queue(): void
    {
        RunScan::dispatch((new ScanConfig(trigger: ScanConfig::TRIGGER_SCHEDULED))->toArray(), null, true);

        Cache::put(self::QUEUED_KEY, time(), self::QUEUED_TTL);

        Log::channel(Plugin::LOG_CHANNEL)->info('Queued a scheduled scan.');
    }

    /** Clears the "queued" flag once the job has run, so the next scan isn't held back. */
    public function markQueueDrained(): void
    {
        Cache::forget(self::QUEUED_KEY);
    }

    public function usesWebTrigger(): bool
    {
        return $this->settings->scheduleEnabled && $this->settings->scheduleTrigger === Settings::TRIGGER_WEB;
    }

    /**
     * The control panel fallback: at most one look a minute, whatever the traffic.
     */
    public function runIfDueFromWeb(): bool
    {
        if (! $this->usesWebTrigger() || ! Cache::add(self::WEB_THROTTLE_KEY, 1, 60)) {
            return false;
        }

        return $this->runIfDue();
    }
}
