<?php

namespace Src\Logistics\Order\UseCases;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Scope\AgencyScope;

/**
 * Listado de órdenes, de la más reciente a la más antigua.
 *
 * El ámbito de agencia llega por constructor: lo deja el middleware
 * `Src\System\Middleware\ResolveAgencyScope` en el contenedor a partir del
 * usuario autenticado, así el caso de uso no depende de `App\Models\User` ni de
 * la petición.
 */
class ListOrdersUseCase
{
    /** Página por defecto cuando la petición no manda `per_page`. */
    public const DEFAULT_PER_PAGE = 15;

    /** Tope de `per_page`: el listado no puede pedir la tabla entera. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AgencyScope $scope,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function execute(int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Order::query()
            // Un rol nacional (`admin`, `analyst`) ve todas; el resto, solo las
            // órdenes que tocan alguna de sus agencias.
            ->forAgencyScope($this->scope)
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
