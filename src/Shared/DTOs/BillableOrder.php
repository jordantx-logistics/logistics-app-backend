<?php

namespace Src\Shared\DTOs;

use Src\Shared\ValueObjects\Money;

/**
 * Fotografía de una orden que Finanzas puede cobrar.
 *
 * La construye el adaptador de Core y viaja por el puerto `OrderQuery`: evita
 * que Finanzas importe el modelo `Order` o haga un JOIN contra `orders`.
 */
final readonly class BillableOrder
{
    public function __construct(
        public int $orderId,
        public string $ticketNumber,
        public string $ticketCode,
        public string $status,
        public Money $totalAmount,
        public int $senderId,
        public int $receiverId,
        public int $originAgencyId,
        public int $destinationAgencyId,
        public string $paymentResponsibility,
        public int $payerId,
        public string $payerName,
        public ?FiscalDocument $fiscalDocument,
    ) {}

    public function isBillable(): bool
    {
        return ! in_array($this->status, ['cancelled'], true);
    }
}
