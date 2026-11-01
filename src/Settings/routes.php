<?php

use Illuminate\Support\Facades\Route;
use Src\Settings\Controllers\SettingsController;

Route::prefix('api/v1/settings')
    ->middleware(['auth:sanctum', 'permission:settings.manage'])
    ->group(function (): void {
        Route::get('/', [SettingsController::class, 'index']);
        Route::patch('/', [SettingsController::class, 'update']);
    });
