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
 * `worked_minutes` suma cada tramo `in`→`out`; `first_in_at`/`last_out_at` son la
 * primera entrada y la última salida. `late_minutes` siempre es 0: sin turnos en
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
        $workedMinutes = 0;

        foreach ($records as $record) {
            if ($record->type === AttendanceRecord::TYPE_IN) {
                $openIn ??= $record->marked_at;
                $firstIn ??= $record->marked_at;
            } elseif ($record->type === AttendanceRecord::TYPE_OUT) {
                if ($openIn !== null) {
                    $workedMinutes += (int) $openIn->diffInMinutes($record->marked_at);
                    $openIn = null;
                }

                $lastOut = $record->marked_at;
            }
        }

        DB::table('attendance_days')->updateOrInsert(
            ['employee_id' => $employeeId, 'work_date' => $workDate],
            [
                'first_in_at' => $firstIn,
                'last_out_at' => $lastOut,
                'worked_minutes' => $workedMinutes,
                'late_minutes' => 0,
                'status' => $firstIn !== null && $lastOut !== null
                    ? self::STATUS_COMPLETE
                    : self::STATUS_INCOMPLETE,
            ],
        );
    }
}
