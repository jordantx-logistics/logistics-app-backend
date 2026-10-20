<?php

namespace Src\Identity\Attendance\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Attendance\Services\AttendanceDayConsolidator;

/**
 * Anulación de una marcación (UC-18).
 *
 * Marca `status = voided` y recalcula el consolidado del día. Una marcación
 * anulada no cuenta para `attendance_days` (`02-entidades.md`). Es idempotente:
 * anular una ya anulada no vuelve a tocar el consolidado.
 */
class VoidAttendanceUseCase
{
    public function __construct(
        private readonly AttendanceDayConsolidator $consolidator,
    ) {}

    public function execute(AttendanceRecord $record): AttendanceRecord
    {
        if ($record->status === AttendanceRecord::STATUS_VOIDED) {
            return $record;
        }

        return DB::transaction(function () use ($record): AttendanceRecord {
            $record->update(['status' => AttendanceRecord::STATUS_VOIDED]);

            $this->consolidator->recompute(
                $record->employee_id,
                $record->marked_at->toDateString(),
            );

            return $record;
        });
    }
}
