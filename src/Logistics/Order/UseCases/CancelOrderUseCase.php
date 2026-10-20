<?php

namespace Src\Logistics\Order\UseCases;

use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;

/**
 * Cancelación de la orden (`pending` o `confirmed` → `cancelled`).
 *
 * El motivo es opcional pero se guarda en `cancelled_reason` y en el histórico:
 * es lo que permite responder por qué se cayó una orden sin abrir los logs.
 */
class CancelOrderUseCase
{
    public function __construct(
        private readonly OrderStatusMachine $machine,
    ) {}

    public function execute(Order $order, ?string $reason = null): Order
    {
        return $this->machine->transition($order, Order::STATUS_CANCELLED, $reason);
    }
}
