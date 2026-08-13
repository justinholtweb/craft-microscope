<?php

namespace justinholtweb\microscope\schedule;

use DateTimeImmutable;
use DateTimeInterface;
use justinholtweb\microscope\models\Settings;

/**
 * Works out when the next scheduled scan is due.
 *
 * Craft has no scheduler, so whatever starts a scan — a cron job running the console command, or a
 * control panel request piggybacking on itself — has to ask "is one due yet?" and answer it from
 * the time of the last one. That question is pure date arithmetic, so it lives here, away from
 * anything that needs a database or a clock, and gets tested against fixed dates.
 *
 * Takes primitives rather than a {@see Settings} object so the tests don't have to construct one.
 */
final class Cadence
{
    public function __construct(
        private readonly string $frequency = Settings::FREQUENCY_WEEKLY,
        private readonly int $hour = 3,
        private readonly int $weekday = 1,
        private readonly int $dayOfMonth = 1,
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            $settings->scheduleFrequency,
            $settings->scheduleHour,
            $settings->scheduleWeekday,
            $settings->scheduleDayOfMonth,
        );
    }

    /**
     * The first scheduled moment strictly after `$point`.
     *
     * Strictly after matters: a scan that finishes at exactly 03:00 must not immediately look due
     * again, which is how you get a scan loop that runs until the queue falls over.
     */
    public function nextRunAfter(DateTimeInterface $point): DateTimeImmutable
    {
        $from = DateTimeImmutable::createFromInterface($point);

        return match ($this->frequency) {
            Settings::FREQUENCY_DAILY => $this->nextDaily($from),
            Settings::FREQUENCY_MONTHLY => $this->nextMonthly($from),
            default => $this->nextWeekly($from),
        };
    }

    /**
     * Whether a scan should run now.
     *
     * A site that has never scanned is due immediately — the first scheduled scan establishes the
     * baseline every later comparison is made against, and waiting up to a month for it makes the
     * feature look broken.
     */
    public function isDue(?DateTimeInterface $lastRun, DateTimeInterface $now): bool
    {
        if ($lastRun === null) {
            return true;
        }

        return $this->nextRunAfter($lastRun) <= DateTimeImmutable::createFromInterface($now);
    }

    private function nextDaily(DateTimeImmutable $from): DateTimeImmutable
    {
        $candidate = $this->atScheduledHour($from);

        return $candidate > $from ? $candidate : $candidate->modify('+1 day');
    }

    private function nextWeekly(DateTimeImmutable $from): DateTimeImmutable
    {
        $candidate = $this->atScheduledHour($from);

        // ISO-8601 weekday: 1 = Monday … 7 = Sunday, which is what the setting stores.
        $currentWeekday = (int)$candidate->format('N');
        $daysAhead = ($this->weekday - $currentWeekday + 7) % 7;

        $candidate = $candidate->modify(sprintf('+%d days', $daysAhead));

        return $candidate > $from ? $candidate : $candidate->modify('+7 days');
    }

    private function nextMonthly(DateTimeImmutable $from): DateTimeImmutable
    {
        // Capped at 28 in settings, so this lands in every month including February.
        $day = max(1, min(28, $this->dayOfMonth));

        $candidate = $this->atScheduledHour($from)->setDate(
            (int)$from->format('Y'),
            (int)$from->format('n'),
            $day,
        );

        if ($candidate > $from) {
            return $candidate;
        }

        // Step to the 1st before adding a month, then set the day. `+1 month` from the 31st lands
        // in the month after next; from the 1st it never does.
        $nextMonth = $candidate
            ->setDate((int)$candidate->format('Y'), (int)$candidate->format('n'), 1)
            ->modify('+1 month');

        return $nextMonth->setDate((int)$nextMonth->format('Y'), (int)$nextMonth->format('n'), $day);
    }

    private function atScheduledHour(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(max(0, min(23, $this->hour)), 0, 0);
    }

    /**
     * A description of the cadence for the settings screen and the report footer.
     */
    public function describe(): string
    {
        $time = sprintf('%02d:00', $this->hour);

        return match ($this->frequency) {
            Settings::FREQUENCY_DAILY => sprintf('Every day at %s', $time),
            Settings::FREQUENCY_MONTHLY => sprintf('The %s of every month at %s', $this->ordinal($this->dayOfMonth), $time),
            default => sprintf('Every %s at %s', $this->weekdayName($this->weekday), $time),
        };
    }

    private function weekdayName(int $weekday): string
    {
        $names = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return $names[$weekday] ?? 'Monday';
    }

    private function ordinal(int $number): string
    {
        $suffix = match (true) {
            $number % 100 >= 11 && $number % 100 <= 13 => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number . $suffix;
    }
}
