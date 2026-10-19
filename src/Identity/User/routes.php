<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\User\Controllers\AuthController;
use Src\Identity\User\Controllers\UserController;

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

/*
|--------------------------------------------------------------------------
| Identity - Usuarios
|--------------------------------------------------------------------------
|
| Gestión de usuarios (UC-01, UC-06, UC-07, UC-16). Cuelga de
| `api/v1/identity/*` (los recursos nuevos de Identity), mientras `api/v1/auth/*`
| sigue siendo el grupo de autenticación del MVP.
|
| La asignación de roles (UC-12) ya vive en `Identity/Role`
| (`POST api/v1/permission/users/{id}/roles`).
|
*/

Route::prefix('api/v1/identity/users')->middleware(['auth:sanctum', 'permission:users.manage'])->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{user}', [UserController::class, 'show'])->whereNumber('user');
    Route::put('/{user}', [UserController::class, 'update'])->whereNumber('user');
    Route::patch('/{user}/status', [UserController::class, 'updateStatus'])->whereNumber('user');
    Route::delete('/{user}', [UserController::class, 'destroy'])->whereNumber('user');
    Route::post('/{user}/unlock', [UserController::class, 'unlock'])->whereNumber('user');
    Route::put('/{user}/agency-scope', [UserController::class, 'assignAgencyScope'])->whereNumber('user');
});
