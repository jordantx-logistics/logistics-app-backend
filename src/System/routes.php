<?php

use Illuminate\Support\Facades\Route;
use Src\System\Controllers\HealthController;
use Src\System\Controllers\MetricsController;
use Src\System\Middleware\EnsureMetricsAccess;

// Readiness para probes (Docker Compose y Kubernetes en Fase 9).
// Público a propósito: un probe no lleva token y no debe depender de `auth:api`.
Route::prefix('api/v1')->group(function () {
    Route::get('health', [HealthController::class, 'show'])->name('system.health');
});

// Scrape de Prometheus, fuera de `api/v1`: lo consume Prometheus, no el
// frontend. El acceso lo filtra `EnsureMetricsAccess`.
Route::get('metrics', MetricsController::class)
    ->middleware(EnsureMetricsAccess::class)
    ->name('system.metrics');
