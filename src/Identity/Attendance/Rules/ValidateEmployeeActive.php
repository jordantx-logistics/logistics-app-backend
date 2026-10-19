<?php

namespace Src\Identity\Attendance\Rules;

use Closure;
use Src\Shared\Contracts\EmployeeDirectory;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * El empleado está en alta (`EMPLOYEE_INACTIVE`).
 *
 * Un empleado `terminated` no puede registrar marcaciones
 * (`02-entidades.md`). El empleado es de `Identity/Employee`, así que se
 * consulta por el puerto `EmployeeDirectory` (ADR-0006).
 */
class ValidateEmployeeActive
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        if (! $this->employees->employeeIsActive((int) $payload['employee_id'])) {
            throw new BusinessRuleException(
                message: 'The employee does not exist or is not active.',
                errorCode: 'EMPLOYEE_INACTIVE',
            );
        }

        return $next($payload);
    }
}
