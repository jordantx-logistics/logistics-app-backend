<?php

namespace Src\Identity\Attendance\Rules;

use Closure;
use Illuminate\Support\Facades\DB;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * `idempotency_key` único (`ATTENDANCE_DUPLICATE`).
 *
 * La app reintenta una marcación offline con la misma clave; el segundo intento
 * no debe duplicar. Es la regla que hace tolerante el registro a los reintentos.
 *
 * Mira la tabla auxiliar: `attendance_records` está particionada y no puede tener un
 * único global. Dos intentos simultáneos los resuelve la clave primaria de esa tabla.
 */
class ValidateAttendanceIdempotency
{
    public function handle(array $payload, Closure $next): mixed
    {
        $exists = DB::table('attendance_idempotency_keys')
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
