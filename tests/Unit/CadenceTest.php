<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Tests\Unit;

use DateTimeImmutable;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Support\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * Craft has no scheduler, so the whole scheduled-scan feature rests on this arithmetic being
 * right. Tested against fixed dates, never against "now".
 */
final class CadenceTest extends TestCase
{
    public function test_daily_picks_the_next_occurrence_of_the_hour(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_DAILY, hour: 3);

        // Before 03:00 → later today.
        self::assertSame(
            '2026-03-10 03:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 01:30'))->format('Y-m-d H:i'),
        );

        // After 03:00 → tomorrow.
        self::assertSame(
            '2026-03-11 03:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 09:00'))->format('Y-m-d H:i'),
        );
    }

    /**
     * A scan finishing at exactly the scheduled moment must not immediately look due again —
     * that's how you get a loop that runs until the queue falls over.
     */
    public function test_exact_scheduled_time_moves_to_the_next_slot(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_DAILY, hour: 3);

        self::assertSame(
            '2026-03-11 03:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 03:00:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_weekly_picks_the_configured_weekday(): void
    {
        // 2026-03-10 is a Tuesday. Weekday 1 is Monday.
        $cadence = new Cadence(Settings::FREQUENCY_WEEKLY, hour: 4, weekday: 1);

        self::assertSame(
            '2026-03-16 04:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 12:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_weekly_on_the_same_weekday_before_the_hour_stays_today(): void
    {
        // 2026-03-10 is a Tuesday; weekday 2 is Tuesday.
        $cadence = new Cadence(Settings::FREQUENCY_WEEKLY, hour: 22, weekday: 2);

        self::assertSame(
            '2026-03-10 22:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 09:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_weekly_on_the_same_weekday_after_the_hour_rolls_a_week(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_WEEKLY, hour: 4, weekday: 2);

        self::assertSame(
            '2026-03-17 04:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 09:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_monthly_picks_the_configured_day(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_MONTHLY, hour: 2, dayOfMonth: 15);

        self::assertSame(
            '2026-03-15 02:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-01 08:00'))->format('Y-m-d H:i'),
        );

        self::assertSame(
            '2026-04-15 02:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-20 08:00'))->format('Y-m-d H:i'),
        );
    }

    /**
     * `+1 month` from the 31st lands in the month *after* next. Stepping to the 1st first is what
     * avoids that, and February is where it shows up.
     */
    public function test_monthly_rolls_correctly_into_february(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_MONTHLY, hour: 2, dayOfMonth: 28);

        self::assertSame(
            '2026-02-28 02:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-01-31 08:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_monthly_rolls_over_a_year_boundary(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_MONTHLY, hour: 2, dayOfMonth: 1);

        self::assertSame(
            '2027-01-01 02:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-12-05 08:00'))->format('Y-m-d H:i'),
        );
    }

    /**
     * A site that has never scanned is due immediately: the first scheduled scan is the baseline
     * every later comparison is made against, and waiting a month for it looks like a bug.
     */
    public function test_never_run_is_due_immediately(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_MONTHLY);

        self::assertTrue($cadence->isDue(null, new DateTimeImmutable('2026-03-10 09:00')));
    }

    public function test_is_due_once_the_next_slot_has_passed(): void
    {
        $cadence = new Cadence(Settings::FREQUENCY_DAILY, hour: 3);
        $lastRun = new DateTimeImmutable('2026-03-10 03:00');

        self::assertFalse($cadence->isDue($lastRun, new DateTimeImmutable('2026-03-10 23:00')));
        self::assertFalse($cadence->isDue($lastRun, new DateTimeImmutable('2026-03-11 02:59')));
        self::assertTrue($cadence->isDue($lastRun, new DateTimeImmutable('2026-03-11 03:00')));
        self::assertTrue($cadence->isDue($lastRun, new DateTimeImmutable('2026-03-12 08:00')));
    }

    public function test_from_settings_reads_the_configured_schedule(): void
    {
        $settings = new Settings;
        $settings->scheduleFrequency = Settings::FREQUENCY_WEEKLY;
        $settings->scheduleHour = 5;
        $settings->scheduleWeekday = 3;

        $cadence = Cadence::fromSettings($settings);

        // 2026-03-10 is a Tuesday; weekday 3 is Wednesday.
        self::assertSame(
            '2026-03-11 05:00',
            $cadence->nextRunAfter(new DateTimeImmutable('2026-03-10 09:00'))->format('Y-m-d H:i'),
        );
    }

    public function test_describes_itself(): void
    {
        self::assertSame(
            'Every day at 03:00',
            (new Cadence(Settings::FREQUENCY_DAILY, hour: 3))->describe(),
        );

        self::assertSame(
            'Every Monday at 04:00',
            (new Cadence(Settings::FREQUENCY_WEEKLY, hour: 4, weekday: 1))->describe(),
        );

        self::assertSame(
            'The 1st of every month at 02:00',
            (new Cadence(Settings::FREQUENCY_MONTHLY, hour: 2, dayOfMonth: 1))->describe(),
        );

        self::assertSame(
            'The 22nd of every month at 02:00',
            (new Cadence(Settings::FREQUENCY_MONTHLY, hour: 2, dayOfMonth: 22))->describe(),
        );
    }
}
