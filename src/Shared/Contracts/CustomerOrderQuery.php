<?php

namespace Src\Shared\Contracts;

/**
 * Puerto hacia `Logistics/Order` para preguntas de solo lectura sobre las
 * órdenes de un cliente.
 *
 * Lo consume el contexto `Customer` (regla `ValidateCustomerHasNoOpenOrders`)
 * para no desactivar un cliente que todavía tiene envíos vivos. El adaptador
 * vive en `Src\Logistics\Order\Adapters` y se enlaza en el composition root,
 * porque `Customer` no puede importar modelos de `Logistics` (ADR-0006).
 */
interface CustomerOrderQuery
{
    /**
     * ¿El cliente tiene órdenes en `pending`, `confirmed` o `in_transit`?
     */
    public function hasOpenOrders(int $customerId): bool;
}
