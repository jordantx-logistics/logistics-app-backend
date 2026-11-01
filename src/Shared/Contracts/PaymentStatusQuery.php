<?php

namespace Src\Shared\Contracts;

use Src\Shared\ValueObjects\Money;

/**
 * Interfaz de dominio que Core usa para conocer el estado de pago de una orden.
 *
 * La implementación vive en Finanzas; Core nunca consulta `payments` ni
 * `billing_orders` directamente. Si Finanzas se extrae a un servicio, esta
 * interfaz se convierte en HTTP/cola sin cambiar al consumidor.
 */
interface PaymentStatusQuery
{
    public function isPaid(int $orderId): bool;

    /**
     * Saldo pendiente (nunca negativo).
     */
    public function balanceOf(int $orderId): Money;

    /**
     * Importe neto cobrado (pagos registrados menos reembolsos y anulaciones).
     */
    public function paidAmountOf(int $orderId): Money;
}
