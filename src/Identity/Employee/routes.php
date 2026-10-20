<?php

use Illuminate\Support\Facades\Route;
use Src\Identity\Employee\Controllers\EmployeeController;

/*
|--------------------------------------------------------------------------
| Identity - Empleados (RRHH)
|--------------------------------------------------------------------------
|
| Los recursos nuevos de Identity cuelgan de `api/v1/identity/*`; las rutas del
| MVP (`api/v1/auth/*`, `api/v1/permission/*`, `api/v1/organization/*`) siguen en
| su prefijo para no romper el frontend ni el contrato versionado.
|
| `whereNumber` no es cosmético: los controladores reciben el modelo por route
| model binding, así que un id no numérico no casa la ruta y responde 404, como
| un id inexistente, en vez de llegar al binding.
|
*/

Route::prefix('api/v1/identity')->middleware('auth:sanctum')->group(function () {
    Route::prefix('employees')->middleware('permission:employees.manage')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::get('/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee');
        Route::put('/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee');
        Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->whereNumber('employee');
    });
});
