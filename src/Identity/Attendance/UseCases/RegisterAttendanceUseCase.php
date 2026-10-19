<?php

namespace Src\Identity\Attendance\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Attendance\Rules\ValidateAttendanceAgency;
use Src\Identity\Attendance\Rules\ValidateAttendanceIdempotency;
use Src\Identity\Attendance\Rules\ValidateAttendanceSequence;
use Src\Identity\Attendance\Rules\ValidateEmployeeActive;
use Src\Identity\Attendance\Services\AttendanceDayConsolidator;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Registro de una marcación (UC-17).
 *
 * Única puerta de entrada: valida por `RulesPipeline` (idempotencia, empleado en
 * alta, agencia válida y secuencia), guarda la marcación y recalcula el
 * consolidado del día en la misma transacción.
 *
 * Self-service acotado: un usuario con `attendance.manage` (RRHH) marca por
 * cualquiera; el resto solo su propia marcación (`users.employee_id`), como pide
 * `07-reglas.md`.
 *
 * No emite `attendance.registered`: el outbox manda a `failed` un `event_type`
 * sin consumidor y todavía no hay proyección que lo escuche (deuda anotada en el
 * cierre de la Fase 2).
 */
class RegisterAttendanceUseCase
{
    public function __construct(
        private readonly AttendanceDayConsolidator $consolidator,
    ) {}

    public function execute(array $data): AttendanceRecord
    {
        $data['employee_id'] = (int) $data['employee_id'];

        $this->authorizeActor($data['employee_id']);

        $data = RulesPipeline::run($data, [
            ValidateAttendanceIdempotency::class,
            ValidateEmployeeActive::class,
            ValidateAttendanceAgency::class,
            ValidateAttendanceSequence::class,
        ]);

        return DB::transaction(function () use ($data): AttendanceRecord {
            $record = AttendanceRecord::create([
                'employee_id' => $data['employee_id'],
                'agency_id' => (int) $data['agency_id'],
                'type' => $data['type'],
                'marked_at' => $data['marked_at'] ?? now(),
                'source' => $data['source'] ?? 'web',
                'device_id' => $data['device_id'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'status' => AttendanceRecord::STATUS_VALID,
                'idempotency_key' => $data['idempotency_key'],
            ]);

            $this->consolidator->recompute(
                $record->employee_id,
                $record->marked_at->toDateString(),
            );

            return $record;
        });
    }

    /**
     * RRHH (`attendance.manage`) puede marcar por cualquiera; el resto, solo por
     * sí mismo.
     */
    private function authorizeActor(int $employeeId): void
    {
        $user = auth()->user();

        if ($user === null || $user->can('attendance.manage')) {
            return;
        }

        if ((int) $user->employee_id !== $employeeId) {
            throw new BusinessRuleException(
                message: 'An employee can only register their own attendance.',
                errorCode: 'ATTENDANCE_FOR_ANOTHER_EMPLOYEE',
                statusCode: 403,
            );
        }
    }
}
