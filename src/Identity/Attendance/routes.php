<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\Attendance\Controllers\AttendanceController;

/*
|--------------------------------------------------------------------------
| Identity - Marcaciones (RRHH)
|--------------------------------------------------------------------------
|
| `attendance.register` lo tiene el propio empleado (`employee`): self-service
| acotado, la regla del caso de uso exige que marque su propia ficha. RRHH
| (`attendance.manage`) puede marcar y anular por cualquiera; `attendance.view`
| es de lectura.
|
*/

Route::prefix('api/v1/identity/attendance')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AttendanceController::class, 'index'])
        ->middleware('permission:attendance.view');
    Route::get('/summary', [AttendanceController::class, 'summary'])
        ->middleware('permission:attendance.view');
    Route::post('/', [AttendanceController::class, 'store'])
        ->middleware('permission:attendance.register');
    Route::post('/{attendance}/void', [AttendanceController::class, 'void'])
        ->whereNumber('attendance')
        ->middleware('permission:attendance.manage');
});
