<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\BillableOrder;

/**
 * Puerto de lectura hacia Core para validar que una orden se puede cobrar.
 *
 * Finanzas pregunta por este puerto en la misma transacción del pago; no
 * consulta `orders` ni hace JOIN (ADR-0006). El adaptador vive en
 * `Src\Logistics\Order\Adapters` y se enlaza en el composition root.
 */
interface OrderQuery
{
    /**
     * Orden con los datos fiscales del pagador, o `null` si no existe.
     */
    public function findBillable(int $orderId): ?BillableOrder;
}
