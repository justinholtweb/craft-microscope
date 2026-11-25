<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Events\ScanCompleted;
use JustinHoltWeb\Microscope\Events\ScanStarting;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Models\ScanFinding;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Services\Scans;
use JustinHoltWeb\Microscope\Tests\Support\FakeCheck;

it('runs every check and stores the scan with its findings', function () {
    $scan = $this->scanWith([
        new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']]),
        new FakeCheck('dbVersion', 'database'),
    ]);

    expect($scan->isCompleted())->toBeTrue()
        ->and($scan->criticalCount)->toBe(1)
        ->and($scan->passCount)->toBe(1)
        ->and($scan->score)->toBe(88)
        ->and($scan->categoryScores)->toBe(['php' => 75, 'database' => 100])
        ->and($scan->config)->toBeInstanceOf(ScanConfig::class)
        ->and(ScanFinding::query()->where('scanId', $scan->id)->count())->toBe(2);

    $stored = app(Scans::class)->getResultForScan($scan->id);
    expect($stored->problems()[0]->title)->toBe('OPcache is off')
        ->and($stored->problems()[0]->evidence)->toHaveCount(30);
});

it('records a check that throws or can’t run as skipped, without scoring it', function () {
    $scan = $this->scanWith([
        new FakeCheck('broken', 'server', throws: true),
        new FakeCheck('remote', 'server', unavailable: 'No shell here.'),
        new FakeCheck('fine', 'php'),
    ]);

    expect($scan->skipped)->toHaveKeys(['broken', 'remote'])
        ->and($scan->skipped['remote'])->toBe('No shell here.')
        ->and($scan->categoryScores)->toBe(['php' => 100])
        ->and($scan->score)->toBe(100);
});

it('honours disabled checks and the scan’s categories', function () {
    $this->useChecks([new FakeCheck('a', 'php'), new FakeCheck('b', 'database'), new FakeCheck('c', 'php')]);
    app(Settings::class)->disabledChecks = ['c'];

    $scan = app(Scans::class)->run(new ScanConfig(categories: ['php']));

    expect(app(Scans::class)->getResultForScan($scan)->findings)->toHaveCount(1);
});

it('says what’s new and what’s resolved since the scan before', function () {
    $this->scanWith([new FakeCheck('x', 'php', [[Finding::SEVERITY_WARNING, 'Old problem', 'a']])]);
    $scan = $this->scanWith([new FakeCheck('x', 'php', [[Finding::SEVERITY_WARNING, 'New problem', 'b']])]);

    $comparison = app(Scans::class)->compareWithPrevious($scan);

    expect(array_map(fn (Finding $f) => $f->title, $comparison['new']))->toBe(['New problem'])
        ->and(array_map(fn (Finding $f) => $f->title, $comparison['resolved']))->toBe(['Old problem']);
});

it('lets a listener cancel a scan, and announces finished ones', function () {
    Event::fake([ScanCompleted::class]);
    $this->scanWith([new FakeCheck('a', 'php')]);
    Event::assertDispatched(ScanCompleted::class);

    Event::listen(ScanStarting::class, fn (ScanStarting $e) => $e->isValid = false);

    expect(fn () => app(Scans::class)->run())->toThrow(RuntimeException::class);
});

it('prunes beyond the retention limit, findings included', function () {
    foreach (range(1, 4) as $i) {
        $this->scanWith([new FakeCheck('a', 'php', [[Finding::SEVERITY_NOTICE, "Notice {$i}"]])]);
    }

    $deleted = app(Scans::class)->prune(2);

    expect($deleted)->toBe(2)
        ->and(Scan::query()->count())->toBe(2)
        ->and(ScanFinding::query()->count())->toBe(2);
});
