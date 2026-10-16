<?php

use Illuminate\Support\Facades\Route;
use Src\System\Controllers\HealthController;

// Readiness para probes (Docker Compose y Kubernetes en Fase 9).
// Público a propósito: un probe no lleva token y no debe depender de `auth:api`.
Route::prefix('api/v1')->group(function () {
    Route::get('health', [HealthController::class, 'show'])->name('system.health');
});
