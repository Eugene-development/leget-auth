<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — LEGET Auth
|--------------------------------------------------------------------------
*/

// ─── Public auth routes ───────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/logout',   [AuthController::class, 'logout']);

    // Email verification (no auth required — link from email)
    Route::get('/email-verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');
});

// ─── Protected auth routes (require valid JWT) ────────────────────────────────
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get('/me',                [AuthController::class, 'me']);
    Route::post('/email-verify/resend', [AuthController::class, 'resendVerification']);
});

// ─── Contact form notification (public, throttled) ───────────────────────────
Route::post('/notify/contact', [NotificationController::class, 'sendContactNotification'])
    ->middleware('throttle:10,1');

Route::post('/notify/service-request', [NotificationController::class, 'sendServiceRequestNotification'])
    ->middleware('throttle:10,1');

