<?php

use Illuminate\Support\Facades\Route;
use Webkul\Zadarma\Http\Controllers\WebhookController;

/**
 * Public webhook route (no 'web' middleware group — no session, no CSRF —
 * this is a machine-to-machine callback from Zadarma's servers, not a
 * browser request). Only loaded when ZADARMA_SYNC_MODE=webhook, see
 * ZadarmaServiceProvider::boot().
 */
Route::post('zadarma/webhook', [WebhookController::class, 'handle'])
    ->middleware('throttle:zadarma-webhook')
    ->name('zadarma.webhook');
