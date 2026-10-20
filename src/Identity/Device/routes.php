<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\Device\Controllers\DeviceController;

/*
|--------------------------------------------------------------------------
| Identity - Dispositivos
|--------------------------------------------------------------------------
|
| UC-19: listar y revocar dispositivos con token. De momento exige
| `devices.manage` (admin): el self-service para ver los propios sin el permiso
| queda como deuda, porque el middleware de la ruta corre antes que la regla.
|
*/

Route::prefix('api/v1/identity/devices')->middleware(['auth:sanctum', 'permission:devices.manage'])->group(function () {
    Route::get('/', [DeviceController::class, 'index']);
    Route::delete('/{device}', [DeviceController::class, 'destroy'])->whereNumber('device');
});
