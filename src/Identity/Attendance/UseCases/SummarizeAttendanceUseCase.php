<?php

namespace Src\Identity\Attendance\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Src\Shared\Scope\AgencyScope;

/**
 * Consolidado diario por empleado (`GET /attendance/summary`).
 *
 * `attendance_days` no guarda `agency_id`, así que el ámbito de agencia se aplica
 * con una subconsulta sobre `attendance_records` (mismo sub-contexto): un
 * empleado entra en el ámbito si alguna de sus marcaciones es de una agencia del
 * usuario.
 */
class SummarizeAttendanceUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /**
     * @param  array{employee_id?: int|string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return DB::table('attendance_days')
            ->when(! $this->scope->unrestricted, function (Builder $query): void {
                $query->whereIn('employee_id', function (Builder $sub): void {
                    $sub->select('employee_id')
                        ->from('attendance_records')
                        ->whereIn('agency_id', $this->scope->ids);
                });
            })
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $query, int|string $employeeId) => $query->where('employee_id', (int) $employeeId),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $query, string $from) => $query->where('work_date', '>=', $from),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $query, string $to) => $query->where('work_date', '<=', $to),
            )
            ->orderByDesc('work_date')
            ->orderBy('employee_id')
            ->paginate($perPage);
    }
}
