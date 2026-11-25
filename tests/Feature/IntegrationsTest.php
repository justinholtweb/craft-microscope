<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Jobs\RunScan;
use JustinHoltWeb\Microscope\Jobs\SendScanWebhook;
use JustinHoltWeb\Microscope\Mail\ScanReportMail;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Notifier;
use JustinHoltWeb\Microscope\Services\Schedules;
use JustinHoltWeb\Microscope\Tests\Support\FakeCheck;

it('queues a signed webhook after a scan, when one is set', function () {
    Bus::fake([SendScanWebhook::class]);
    $settings = app(Settings::class);
    $settings->webhookUrl = 'https://hooks.example.com/microscope';
    $settings->webhookSecret = 's3cret';

    $this->scanWith([new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']])]);

    Bus::assertDispatched(SendScanWebhook::class, function (SendScanWebhook $job) {
        expect($job->payload)->toMatchArray(['event' => 'scan.completed', 'previousScore' => null])
            ->and($job->payload['problems'][0]['title'])->toBe('OPcache is off')
            ->and($job->payload['new'])->toHaveCount(1);

        Http::fake();
        $job->handle(app(Settings::class));
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/microscope'
                && $request->header('X-Microscope-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 's3cret');
        });

        return true;
    });
});

it('sends no webhook when none is set', function () {
    Bus::fake([SendScanWebhook::class]);
    $this->scanWith([new FakeCheck('a', 'php')]);
    Bus::assertNotDispatched(SendScanWebhook::class);
});

it('notifies by email and Slack only when something is new', function () {
    Mail::fake();
    Http::fake();
    $settings = app(Settings::class);
    $settings->notifyEnabled = true;
    $settings->notifyRecipients = ['ops@example.com', 'dev@example.com'];
    $settings->slackWebhookUrl = 'https://hooks.slack.com/services/T/B/x';
    $settings->attachPdf = false;

    $first = $this->scanWith([new FakeCheck('a', 'php', [[Finding::SEVERITY_WARNING, 'Slow', 'x']])], ScanConfig::TRIGGER_SCHEDULED);
    expect(app(Notifier::class)->scheduledScanFinished($first))->toBeTrue();
    Mail::assertSent(ScanReportMail::class, 2);
    Http::assertSentCount(1);

    $same = $this->scanWith([new FakeCheck('a', 'php', [[Finding::SEVERITY_WARNING, 'Slow', 'x']])], ScanConfig::TRIGGER_SCHEDULED);
    expect(app(Notifier::class)->scheduledScanFinished($same))->toBeFalse();
});

it('queues one scheduled scan however many callers ask', function () {
    Bus::fake([RunScan::class]);
    $settings = app(Settings::class);
    $settings->scheduleEnabled = true;

    $schedules = app(Schedules::class);

    expect($schedules->runIfDue())->toBeTrue()
        ->and($schedules->runIfDue())->toBeFalse();
    Bus::assertDispatchedTimes(RunScan::class, 1);

    $schedules->markQueueDrained();
    expect($schedules->isDue())->toBeTrue();
});

it('registers the scheduled scan on Laravel’s scheduler', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description);

    expect($events)->toContain('microscope:scan:scheduled');
});

it('runs from the console under its Craft 5 name too', function () {
    $this->useChecks([new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']])]);

    $this->artisan('microscope:scan:run')->assertSuccessful();
    $this->artisan('craft:microscope/scan/run', ['--fail-on' => 'critical'])->assertFailed();
    $this->artisan('microscope:scan:list')->assertSuccessful();
});
