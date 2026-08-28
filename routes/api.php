<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — LEGET Auth
|--------------------------------------------------------------------------
*/

// ─── Public auth routes ───────────────────────────────────────────────────────
Route::post('/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [AuthController::class, 'logout']);

    // Email verification (no auth required — link from email)
    Route::get('/email-verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify');
});

// ─── Client auth routes (роль «Клиент») ───────────────────────────────────────
//
// Обслуживают личный кабинет посетителя сайта тенанта (leget-main). Вызываются
// серверной частью leget-main, поэтому лимит считается по email, а не по IP:
// все запросы приходят с одного адреса контейнера, и throttle по IP запер бы
// вход сразу всем. См. RateLimiter 'client-auth' в AppServiceProvider.
Route::prefix('client')->group(function () {
    Route::post('/register', [ClientAuthController::class, 'register'])->middleware('throttle:client-auth');
    Route::post('/login', [ClientAuthController::class, 'login'])->middleware('throttle:client-auth');
});

// ─── Заявка на партнёрство ────────────────────────────────────────────────────
//
// Без `can:partner.cabinet`: заявку подаёт КЛИЕНТ, партнёром он станет только
// после разбора. Способность здесь заперла бы дверь ровно тем, ради кого
// маршрут существует.
Route::prefix('partner')->middleware('auth:api')->group(function () {
    Route::post('/apply', [PartnerApplicationController::class, 'apply'])->middleware('throttle:10,1');
    Route::get('/application', [PartnerApplicationController::class, 'mine']);
});

// ─── «Кто я»: профиль вошедшего любой роли ────────────────────────────────────
//
// Без `can:`: маршрут отвечает и клиенту, и партнёру, и админу — leget-main по
// нему решает, чей кабинет показывать. Границей доступа к самому кабинету
// остаются способности на маршрутах данных, а не этот ответ.
Route::prefix('session')->middleware('auth:api')->group(function () {
    Route::get('/me', [SessionController::class, 'me']);
});

// ─── Protected auth routes (require valid JWT) ────────────────────────────────
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/email-verify/resend', [AuthController::class, 'resendVerification']);
});

// ─── Contact form notification (public, throttled) ───────────────────────────
Route::post('/notify/contact', [NotificationController::class, 'sendContactNotification'])
    ->middleware('throttle:10,1');

Route::post('/notify/service-request', [NotificationController::class, 'sendServiceRequestNotification'])
    ->middleware('throttle:10,1');
