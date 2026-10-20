<?php

namespace Src\Logistics\Order\UseCases;

use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderStatusMachine;

/**
 * Confirmación de la orden (`pending` → `confirmed`).
 *
 * A partir de aquí el precio queda congelado: el snapshot ya está guardado y las
 * ediciones de reparto que lo cambiarían se rechazan
 * (`UpdateOrderDeliveryUseCase`).
 */
class ConfirmOrderUseCase
{
    public function __construct(
        private readonly OrderStatusMachine $machine,
    ) {}

    public function execute(Order $order): Order
    {
        return $this->machine->transition($order, Order::STATUS_CONFIRMED);
    }
}
