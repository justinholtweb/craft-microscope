<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Microscope\Http\Controllers\ChecksController;
use JustinHoltWeb\Microscope\Http\Controllers\DashboardController;
use JustinHoltWeb\Microscope\Http\Controllers\ReportsController;
use JustinHoltWeb\Microscope\Http\Controllers\ScansController;
use JustinHoltWeb\Microscope\Plugin;

// The CP group already applies `auth`, `can:accessCp` and the cpTrigger prefix. Craft only hides
// the nav item from users without access; these routes check Microscope's permissions themselves.

Route::middleware('can:'.Plugin::PERMISSION_VIEW)->group(function () {
    Route::get('microscope', DashboardController::class)->name('microscope');

    Route::get('microscope/scans', [ScansController::class, 'index'])->name('microscope.scans');
    Route::get('microscope/scans/status', [ScansController::class, 'status'])->name('microscope.scans.status');
    Route::get('microscope/scans/{scanId}', [ScansController::class, 'show'])->whereNumber('scanId')->name('microscope.scans.show');
    Route::get('microscope/scans/{scanId}/report', [ReportsController::class, 'print'])->whereNumber('scanId')->name('microscope.reports.print');
    Route::get('microscope/scans/{scanId}/report.pdf', [ReportsController::class, 'pdf'])->whereNumber('scanId')->name('microscope.reports.pdf');
    Route::get('microscope/checks', [ChecksController::class, 'index'])->name('microscope.checks');

    Route::post('microscope/scans/run', [ScansController::class, 'store'])
        ->middleware('can:'.Plugin::PERMISSION_SCAN)
        ->name('microscope.scans.run');

    Route::middleware('can:'.Plugin::PERMISSION_MANAGE)->group(function () {
        Route::post('microscope/scans/{scanId}/delete', [ScansController::class, 'destroy'])->whereNumber('scanId');
        Route::post('microscope/scans/{scanId}/email', [ScansController::class, 'email'])->whereNumber('scanId');
        Route::post('microscope/checks/toggle', [ChecksController::class, 'toggle'])->name('microscope.checks.toggle');
    });
});
