<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\User\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
|
| Sanctum con dos modos: cookie de sesión para la SPA
| (`EnsureFrontendRequestsAreStateful` + CSRF) y tokens opacos para apps/PDA.
| `GET /sanctum/csrf-cookie` lo registra el propio paquete.
|
| `throttle:auth` (ver `AppServiceProvider`) limita por IP (10/min) y por
| email+IP (5/min): el criterio 11 de la Fase 1 exige 429 al superarlos.
|
*/

Route::prefix('api/v1/auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('token', [AuthController::class, 'token']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
    });
});
