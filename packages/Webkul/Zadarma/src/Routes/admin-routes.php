<?php

use Illuminate\Support\Facades\Route;
use Webkul\Zadarma\Http\Controllers\CallController;
use Webkul\Zadarma\Http\Controllers\ReportController;
use Webkul\Zadarma\Http\Controllers\UserExtensionController;

/**
 * Authenticated admin routes, matching Krayin's own Admin route group
 * (confirmed in AdminServiceProvider::boot()). Always registered,
 * independent of ZADARMA_SYNC_MODE — click-to-call has nothing to do
 * with how call history is discovered.
 */
Route::middleware(['web', 'admin_locale', 'user'])
    ->prefix(config('app.admin_path'))
    ->group(function () {
        Route::post('zadarma/call', [CallController::class, 'store'])->name('admin.zadarma.call');

        Route::put('zadarma/my-extension', [UserExtensionController::class, 'update'])->name('admin.zadarma.my-extension.update');

        Route::get('zadarma/reports', [ReportController::class, 'index'])->name('admin.zadarma.reports.index');

        Route::get('zadarma/reports/data', [ReportController::class, 'data'])->name('admin.zadarma.reports.data');
    });
