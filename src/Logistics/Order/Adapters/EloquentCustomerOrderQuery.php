<?php

namespace Src\Logistics\Order\Adapters;

use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\CustomerOrderQuery;

/**
 * Adaptador Eloquent del puerto `CustomerOrderQuery`.
 *
 * Cuenta como «abierta» toda orden que no esté `delivered` ni `cancelled`: son
 * las que impiden desactivar al cliente (`spec/04-modulos/core-logistica/07-reglas.md`).
 */
class EloquentCustomerOrderQuery implements CustomerOrderQuery
{
    /** Estados en los que la orden sigue viva. */
    public const OPEN_STATUSES = ['pending', 'confirmed', 'in_transit'];

    public function hasOpenOrders(int $customerId): bool
    {
        return Order::query()
            ->where(fn ($query) => $query
                ->where('sender_id', $customerId)
                ->orWhere('receiver_id', $customerId))
            ->whereIn('status', self::OPEN_STATUSES)
            ->exists();
    }
}
