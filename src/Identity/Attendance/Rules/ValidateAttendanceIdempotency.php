<?php

namespace Src\Identity\Attendance\Rules;

use Closure;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * `idempotency_key` único (`ATTENDANCE_DUPLICATE`).
 *
 * La app reintenta una marcación offline con la misma clave; el segundo intento
 * no debe duplicar. Es la regla que hace tolerante el registro a los reintentos.
 */
class ValidateAttendanceIdempotency
{
    public function handle(array $payload, Closure $next): mixed
    {
        $exists = AttendanceRecord::query()
            ->where('idempotency_key', $payload['idempotency_key'])
            ->exists();

        if ($exists) {
            throw new BusinessRuleException(
                message: 'This attendance has already been registered.',
                errorCode: 'ATTENDANCE_DUPLICATE',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
