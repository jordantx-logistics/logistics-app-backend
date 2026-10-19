<?php

namespace Src\Identity\Employee\Policies;

use App\Models\User;
use Src\Identity\Employee\Models\Employee;
use Src\Shared\Scope\AgencyScope;

/**
 * Reglas por registro sobre empleados.
 *
 * El `permission:employees.manage` de la ruta decide *qué acción*; esta policy
 * decide *sobre qué empleado*: un usuario operativo solo alcanza los de sus
 * agencias, y un rol nacional (`admin`, `analyst`) cualquiera. El listado ya lo
 * acota el scope; aquí se cierra el hueco de pedir por id el empleado de otra
 * agencia.
 *
 * El ámbito llega por constructor: es el de la petición
 * (`Src\System\Middleware\ResolveAgencyScope`); fuera de una petición
 * autenticada es vacío y la respuesta por defecto es denegar.
 */
class EmployeePolicy
{
    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /** El listado lo acota el scope: no hay regla por registro. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->inScope($employee);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->inScope($employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->inScope($employee);
    }

    private function inScope(Employee $employee): bool
    {
        return $this->scope->unrestricted
            || in_array((int) $employee->agency_id, $this->scope->ids, true);
    }
}
