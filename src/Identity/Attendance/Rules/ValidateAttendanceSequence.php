<?php

namespace Src\Identity\Attendance\Rules;

use Closure;
use Illuminate\Support\Carbon;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Secuencia de marcaciones coherente dentro de la jornada
 * (`ATTENDANCE_SEQUENCE_INVALID`).
 *
 * La jornada es un pequeño autómata: se entra (`in`), se sale (`out`) y en medio
 * puede haber un descanso (`break-start` → `break-end`). Cada tipo solo se admite
 * desde ciertos estados; la regla mira la última marcación válida del mismo día
 * (una anulada no cuenta).
 *
 * No hay turnos en el alcance (`fase-02`), así que no se fuerza una hora de
 * entrada ni se marca `late`.
 */
class ValidateAttendanceSequence
{
    /**
     * Estados previos admitidos por tipo. `null` = sin marcación previa ese día.
     *
     * @var array<string, list<string|null>>
     */
    private const ALLOWED_PREVIOUS = [
        AttendanceRecord::TYPE_IN => [null, AttendanceRecord::TYPE_OUT, AttendanceRecord::TYPE_BREAK_END],
        AttendanceRecord::TYPE_OUT => [AttendanceRecord::TYPE_IN, AttendanceRecord::TYPE_BREAK_START],
        AttendanceRecord::TYPE_BREAK_START => [AttendanceRecord::TYPE_IN],
        AttendanceRecord::TYPE_BREAK_END => [AttendanceRecord::TYPE_BREAK_START],
    ];

    public function handle(array $payload, Closure $next): mixed
    {
        $markedAt = Carbon::parse($payload['marked_at'] ?? now());

        $previous = AttendanceRecord::query()
            ->where('employee_id', $payload['employee_id'])
            ->whereDate('marked_at', $markedAt->toDateString())
            ->where('status', '!=', AttendanceRecord::STATUS_VOIDED)
            ->orderByDesc('marked_at')
            ->value('type');

        $allowed = self::ALLOWED_PREVIOUS[$payload['type']] ?? [];

        if (! in_array($previous, $allowed, true)) {
            throw new BusinessRuleException(
                message: 'The attendance sequence is not valid for that type.',
                errorCode: 'ATTENDANCE_SEQUENCE_INVALID',
            );
        }

        return $next($payload);
    }
}
