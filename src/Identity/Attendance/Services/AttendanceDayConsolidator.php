<?php

namespace Src\Identity\Attendance\Services;

use Illuminate\Support\Facades\DB;
use Src\Identity\Attendance\Models\AttendanceRecord;

/**
 * Recalcula el consolidado diario (`attendance_days`) de un empleado.
 *
 * `attendance_days` es una proyección interna con clave natural
 * `[employee_id, work_date]` (sin `id` ni `timestamps`), por eso se escribe con
 * el query builder (`updateOrInsert`) en vez de con un modelo Eloquent de clave
 * compuesta.
 *
 * `worked_minutes` suma el tiempo entre `in` y `out` sin el descanso, que va a
 * `lunch_minutes`; `first_in_at`/`last_out_at` son la primera entrada y la última
 * salida. Volver del descanso reanuda el trabajo sin otro `in`, y una salida durante
 * el descanso lo cierra. `late_minutes` siempre es 0: sin turnos en
 * el alcance (`fase-02`) no hay hora de referencia con la que comparar.
 */
class AttendanceDayConsolidator
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    public function recompute(int $employeeId, string $workDate): void
    {
        $records = AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereDate('marked_at', $workDate)
            ->where('status', '!=', AttendanceRecord::STATUS_VOIDED)
            ->orderBy('marked_at')
            ->get(['type', 'marked_at']);

        if ($records->isEmpty()) {
            DB::table('attendance_days')
                ->where('employee_id', $employeeId)
                ->where('work_date', $workDate)
                ->delete();

            return;
        }

        $firstIn = null;
        $lastOut = null;
        $openIn = null;
        $openBreak = null;
        $workedMinutes = 0;
        $lunchMinutes = 0;

        foreach ($records as $record) {
            $at = $record->marked_at;

            if ($record->type === AttendanceRecord::TYPE_IN) {
                $openIn ??= $at;
                $firstIn ??= $at;

                continue;
            }

            if ($openIn !== null) {
                $workedMinutes += (int) $openIn->diffInMinutes($at);
                $openIn = null;
            }

            if ($openBreak !== null) {
                $lunchMinutes += (int) $openBreak->diffInMinutes($at);
                $openBreak = null;
            }

            match ($record->type) {
                AttendanceRecord::TYPE_BREAK_START => $openBreak = $at,
                AttendanceRecord::TYPE_BREAK_END => $openIn = $at,
                AttendanceRecord::TYPE_OUT => $lastOut = $at,
                default => null,
            };
        }

        DB::table('attendance_days')->updateOrInsert(
            ['employee_id' => $employeeId, 'work_date' => $workDate],
            [
                'first_in_at' => $firstIn,
                'last_out_at' => $lastOut,
                'worked_minutes' => $workedMinutes,
                'lunch_minutes' => $lunchMinutes,
                'late_minutes' => 0,
                'status' => $firstIn !== null && $lastOut !== null
                    ? self::STATUS_COMPLETE
                    : self::STATUS_INCOMPLETE,
            ],
        );
    }
}
