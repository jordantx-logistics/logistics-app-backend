<?php

namespace Src\Shared\DTOs;

/**
 * Foto plana de un envío para los subcontextos de Operaciones.
 *
 * `Reception`, `Inventory`, `Tracking` y `Delivery` no pueden importar el modelo
 * `Operations\Shipment\Models\Shipment` (ADR-0006): lo obtienen por el puerto
 * `ShipmentDirectory` y reciben este DTO.
 */
final class ShipmentSnapshot
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $ticketCode,
        public readonly string $ticketNumber,
        public readonly int $originAgencyId,
        public readonly int $destinationAgencyId,
        public readonly ?int $currentLocationAgencyId,
        public readonly string $status,
        public readonly ?string $deliveredAt = null,
    ) {}
}
