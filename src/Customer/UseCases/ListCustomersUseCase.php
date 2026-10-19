<?php

namespace Src\Customer\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Customer\Models\Customer;

/**
 * Listado de clientes, del más reciente al más antiguo.
 *
 * Los clientes no están limitados por agencia (un remitente puede enviar desde
 * cualquiera), así que aquí no hay `AgencyScope`: el filtro es de negocio.
 */
class ListCustomersUseCase
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{q?: string|null, customer_type?: string|null, is_active?: bool|null}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function execute(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Customer::query()
            ->when(
                isset($filters['q']) && $filters['q'] !== '',
                fn ($query) => $query->search((string) $filters['q']),
            )
            ->when(
                isset($filters['customer_type']) && $filters['customer_type'] !== '',
                fn ($query) => $query->where('customer_type', $filters['customer_type']),
            )
            ->when(
                isset($filters['is_active']),
                fn ($query) => $query->where('is_active', (bool) $filters['is_active']),
            )
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
