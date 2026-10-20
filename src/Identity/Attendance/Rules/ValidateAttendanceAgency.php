<?php

namespace Src\Identity\Attendance\Rules;

use Closure;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * La agencia de la marcación existe y está activa.
 *
 * `attendance_records.agency_id` no lleva FK (`agencies` es de Organization,
 * ADR-0006), así que aquí se cierra el hueco de guardar un id inexistente: se
 * pregunta por el puerto `AgencyDirectory`.
 */
class ValidateAttendanceAgency
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        if (! $this->agencies->agencyIsActive((int) $payload['agency_id'])) {
            throw new BusinessRuleException(
                message: 'The selected agency does not exist or is inactive.',
                errorCode: 'ATTENDANCE_AGENCY_INVALID',
            );
        }

        return $next($payload);
    }
}
