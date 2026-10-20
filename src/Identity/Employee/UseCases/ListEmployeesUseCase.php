<?php

namespace Src\Identity\Employee\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Src\Identity\Employee\Models\Employee;
use Src\Shared\Scope\AgencyScope;

/**
 * Listado de empleados con filtros por estado, agencia y texto.
 *
 * El ámbito de agencia llega por constructor (middleware `ResolveAgencyScope`):
 * un rol nacional ve todos, el resto solo los de sus agencias.
 */
class ListEmployeesUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /**
     * @param  array{status?: string|null, agency_id?: int|string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Employee::query()
            ->forAgencyScope($this->scope)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(
                $filters['agency_id'] ?? null,
                fn (Builder $query, int|string $agencyId) => $query->where('agency_id', (int) $agencyId),
            )
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('document_number', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);
    }
}
