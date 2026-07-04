<?php

use Illuminate\Support\Facades\Route;
use Webkul\Zadarma\Http\Controllers\CallController;

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
    });
