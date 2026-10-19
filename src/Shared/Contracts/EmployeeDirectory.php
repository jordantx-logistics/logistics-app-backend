<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia el sub-contexto `Identity/Employee` (RRHH).
 *
 * `Identity/Attendance` necesita saber si un empleado existe y está en alta,
 * pero los sub-dominios de Identity son contextos de pleno derecho y no se
 * importan entre sí (ADR-0006). El adaptador que conoce el modelo vive en
 * `Src\Identity\Employee\Adapters` y se enlaza en el composition root.
 */
interface EmployeeDirectory
{
    /** ¿Existe un empleado con ese id? */
    public function employeeExists(int $id): bool;

    /** ¿Está en alta (`status = active`)? Un empleado dado de baja no marca. */
    public function employeeIsActive(int $id): bool;

    /** Agencia base del empleado, si el empleado existe. */
    public function employeeAgencyId(int $id): ?int;
}
