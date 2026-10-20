<?php

namespace Src\Identity\Attendance\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Shared\Scope\AgencyScope;

/**
 * Listado de marcaciones con filtros por empleado, agencia, estado y rango de
 * fechas (paso 3 de la Fase 2).
 *
 * El ámbito de agencia llega por constructor (middleware `ResolveAgencyScope`):
 * un rol nacional ve todas, el resto solo las de sus agencias.
 */
class ListAttendanceUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /**
     * @param  array{employee_id?: int|string|null, agency_id?: int|string|null, status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, AttendanceRecord>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return AttendanceRecord::query()
            ->forAgencyScope($this->scope)
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $query, int|string $employeeId) => $query->where('employee_id', (int) $employeeId),
            )
            ->when(
                $filters['agency_id'] ?? null,
                fn (Builder $query, int|string $agencyId) => $query->where('agency_id', (int) $agencyId),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('status', $status),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from) => $query->where('marked_at', '>=', Carbon::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $query, string $to) => $query->where('marked_at', '<=', Carbon::parse($to)->endOfDay()),
            )
            ->orderByDesc('marked_at')
            ->paginate($perPage);
    }
}
