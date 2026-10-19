<?php

namespace Src\Identity\Attendance\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Attendance\Requests\RegisterAttendanceRequest;
use Src\Identity\Attendance\Resources\AttendanceDayResource;
use Src\Identity\Attendance\Resources\AttendanceResource;
use Src\Identity\Attendance\UseCases\ListAttendanceUseCase;
use Src\Identity\Attendance\UseCases\RegisterAttendanceUseCase;
use Src\Identity\Attendance\UseCases\SummarizeAttendanceUseCase;
use Src\Identity\Attendance\UseCases\VoidAttendanceUseCase;

/**
 * @group Identity - Attendance
 *
 * APIs para registrar, anular y consultar marcaciones de asistencia (UC-17/UC-18).
 */
class AttendanceController extends Controller
{
    /**
     * List Attendance
     *
     * Lista las marcaciones visibles para el usuario, filtrables por empleado, agencia, estado y rango de fechas.
     *
     * @queryParam employee_id integer Filtra por empleado. Example: 55
     * @queryParam agency_id integer Filtra por agencia. Example: 42
     * @queryParam status string valid,late,duplicate,voided. Example: valid
     * @queryParam from string Fecha ISO-8601 inferior (inclusive). Example: 2026-10-01
     * @queryParam to string Fecha ISO-8601 superior (inclusive). Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @apiResourceCollection Src\Identity\Attendance\Resources\AttendanceResource
     *
     * @apiResourceModel Src\Identity\Attendance\Models\AttendanceRecord paginate=15
     *
     * @apiResourceAdditional success=true message="Attendance retrieved successfully."
     */
    public function index(Request $request, ListAttendanceUseCase $useCase): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $perPage = min(
            max($request->integer('per_page', ListAttendanceUseCase::DEFAULT_PER_PAGE), 1),
            ListAttendanceUseCase::MAX_PER_PAGE,
        );

        $records = $useCase->execute(
            $request->only(['employee_id', 'agency_id', 'status', 'from', 'to']),
            $perPage,
        );

        return $this->successPaginated(
            resource: AttendanceResource::collection($records),
            message: 'Attendance retrieved successfully.',
        );
    }

    /**
     * Attendance Summary
     *
     * Consolidado diario por empleado (`attendance_days`), filtrable por empleado y rango de fechas.
     *
     * @queryParam employee_id integer Filtra por empleado. Example: 55
     * @queryParam from string Fecha inferior (inclusive). Example: 2026-10-01
     * @queryParam to string Fecha superior (inclusive). Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @response 200 {"success": true, "message": "Attendance summary retrieved successfully.", "data": [{"employee_id": 55, "work_date": "2026-10-15", "first_in_at": "2026-10-15 08:02:11", "last_out_at": "2026-10-15 17:30:00", "worked_minutes": 568, "late_minutes": 0, "status": "complete"}]}
     */
    public function summary(Request $request, SummarizeAttendanceUseCase $useCase): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $perPage = min(
            max($request->integer('per_page', SummarizeAttendanceUseCase::DEFAULT_PER_PAGE), 1),
            SummarizeAttendanceUseCase::MAX_PER_PAGE,
        );

        $days = $useCase->execute(
            $request->only(['employee_id', 'from', 'to']),
            $perPage,
        );

        return $this->successPaginated(
            resource: AttendanceDayResource::collection($days),
            message: 'Attendance summary retrieved successfully.',
        );
    }

    /**
     * Register Attendance
     *
     * Registra una marcación de entrada/salida de un empleado. Es idempotente por `idempotency_key`, por lo que los reintentos offline no duplican registros.
     *
     * @apiResource Src\Identity\Attendance\Resources\AttendanceResource status=201
     *
     * @apiResourceModel Src\Identity\Attendance\Models\AttendanceRecord
     *
     * @apiResourceAdditional success=true message="Attendance registered successfully."
     */
    public function store(RegisterAttendanceRequest $request, RegisterAttendanceUseCase $useCase): JsonResponse
    {
        $record = $useCase->execute($request->validated());

        return $this->success(
            data: AttendanceResource::make($record),
            message: 'Attendance registered successfully.',
            statusCode: 201,
        );
    }

    /**
     * Void Attendance
     *
     * Anula una marcación (RRHH). El consolidado del día se recalcula sin ella.
     *
     * @response 200 {"success": true, "message": "Attendance voided successfully."}
     */
    public function void(AttendanceRecord $attendance, VoidAttendanceUseCase $useCase): JsonResponse
    {
        $this->authorize('void', $attendance);

        $useCase->execute($attendance);

        return $this->success(message: 'Attendance voided successfully.');
    }
}
