<?php

namespace Src\Logistics\Order\UseCases;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Src\Logistics\Order\Models\Order;

/**
 * Listado de órdenes, de la más reciente a la más antigua.
 *
 * El usuario llega por parámetro y no se inyecta en el constructor: el
 * contenedor resolvería `App\Models\User` como un modelo vacío y el ámbito de
 * agencia se perdería en silencio. Lo pide el controlador a `$request->user()`,
 * igual que hace el listado de agencias.
 */
class ListOrdersUseCase
{
    /** Página por defecto cuando la petición no manda `per_page`. */
    public const DEFAULT_PER_PAGE = 15;

    /** Tope de `per_page`: el listado no puede pedir la tabla entera. */
    public const MAX_PER_PAGE = 100;

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function execute(User $user, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return Order::query()
            // Ámbito de agencia: un rol nacional (`admin`, `analyst`) ve todas;
            // el resto, solo las órdenes que tocan alguna de sus agencias.
            ->when(
                ! $user->hasNationalScope(),
                fn ($query) => $query->forAgencies($user->agencyIds()),
            )
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
