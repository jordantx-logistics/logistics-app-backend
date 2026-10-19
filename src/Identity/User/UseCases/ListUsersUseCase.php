<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de usuarios con filtros por estado, texto y agencia (grupo «Usuarios y
 * empleados» del API).
 *
 * No hay scoping por agencia: la ruta exige `users.manage` (administración), que
 * es un permiso de alcance nacional. El filtro `agency_id` es de consulta, no de
 * autorización.
 */
class ListUsersUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string|null, q?: string|null, agency_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return User::query()
            ->with('agencies')
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('status', $status),
            )
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.$term.'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when(
                $filters['agency_id'] ?? null,
                fn (Builder $query, int|string $agencyId) => $query->whereHas(
                    'agencies',
                    fn (Builder $agencies) => $agencies->where('agencies.id', (int) $agencyId),
                ),
            )
            ->orderBy('name')
            ->paginate($perPage);
    }
}
