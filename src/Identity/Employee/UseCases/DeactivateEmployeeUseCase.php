<?php

namespace Src\Identity\Employee\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Src\Identity\Employee\Models\Employee;

/**
 * Baja de un empleado (UC-09): `status = terminated`, `terminated_at = now`.
 *
 * Si el empleado tenía usuario de sistema, se desactiva (`status = inactive`):
 * un usuario inactivo no se autentica (`USER_INACTIVE`). No se borra la fila: el
 * histórico de marcaciones debe seguir resolviendo al empleado.
 */
class DeactivateEmployeeUseCase
{
    public function execute(Employee $employee): Employee
    {
        return DB::transaction(function () use ($employee): Employee {
            $employee->update([
                'status' => Employee::STATUS_TERMINATED,
                'terminated_at' => now()->toDateString(),
            ]);

            // `App\Models\User` es el scaffolding de Laravel (no un contexto), así
            // que la desactivación del usuario vinculado vive aquí y no en un
            // puerto. `App\Models\User` guarda el vínculo en `employee_id`.
            User::query()
                ->where('employee_id', $employee->id)
                ->update(['status' => 'inactive']);

            return $employee;
        });
    }
}
