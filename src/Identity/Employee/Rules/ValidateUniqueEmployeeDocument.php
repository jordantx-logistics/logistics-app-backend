<?php

namespace Src\Identity\Employee\Rules;

use Closure;
use Src\Identity\Employee\Models\Employee;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * `document_number` único entre empleados (`EMPLOYEE_DOCUMENT_TAKEN`).
 *
 * En una edición el `UseCase` inyecta `employee_id` en el payload para no
 * chocar con el propio registro.
 */
class ValidateUniqueEmployeeDocument
{
    public function handle(array $payload, Closure $next): mixed
    {
        $query = Employee::query()
            ->where('document_type', $payload['document_type'])
            ->where('document_number', $payload['document_number']);

        if (isset($payload['employee_id'])) {
            $query->whereKeyNot($payload['employee_id']);
        }

        if ($query->exists()) {
            throw new BusinessRuleException(
                message: 'An employee with that document number already exists.',
                errorCode: 'EMPLOYEE_DOCUMENT_TAKEN',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
