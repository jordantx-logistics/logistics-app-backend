<?php

namespace Src\Logistics\Tariff\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Logistics\Tariff\Models\Tariff;

/**
 * Listado de tarifarios, del más reciente al más antiguo.
 *
 * Los tarifarios no están acotados por agencia: son reglas nacionales o por
 * par de zonas/ubicaciones, y los administra quien tiene `tariffs.manage`.
 */
class ListTariffsUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{q?: string|null, service_type?: string|null, scope_type?: string|null, is_active?: bool|null}  $filters
     * @return LengthAwarePaginator<int, Tariff>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Tariff::query()
            ->with('brackets')
            ->when(
                isset($filters['q']) && $filters['q'] !== '',
                fn ($query) => $query->where(fn ($query) => $query
                    ->where('code', 'like', '%'.$filters['q'].'%')
                    ->orWhere('name', 'like', '%'.$filters['q'].'%')),
            )
            ->when(
                isset($filters['service_type']) && $filters['service_type'] !== '',
                fn ($query) => $query->where('service_type', $filters['service_type']),
            )
            ->when(
                isset($filters['scope_type']) && $filters['scope_type'] !== '',
                fn ($query) => $query->where('scope_type', $filters['scope_type']),
            )
            ->when(
                isset($filters['is_active']),
                fn ($query) => $query->where('is_active', (bool) $filters['is_active']),
            )
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
