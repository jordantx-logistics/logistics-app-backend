<?php

namespace Src\Identity\Employee\Rules;

use Closure;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * La agencia del empleado existe y está activa (`EMPLOYEE_AGENCY_INVALID`).
 *
 * `agencies` es de Organization: se consulta por el puerto `AgencyDirectory`,
 * nunca por el modelo ajeno (ADR-0006). La regla se resuelve desde el
 * contenedor, así que el puerto llega por constructor.
 */
class ValidateEmployeeAgency
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        $agencyId = (int) $payload['agency_id'];

        if (! $this->agencies->agencyIsActive($agencyId)) {
            throw new BusinessRuleException(
                message: 'The selected agency does not exist or is inactive.',
                errorCode: 'EMPLOYEE_AGENCY_INVALID',
            );
        }

        return $next($payload);
    }
}
