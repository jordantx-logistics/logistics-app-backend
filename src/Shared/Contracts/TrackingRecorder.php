<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\ShipmentSnapshot;

/**
 * Puerto hacia `Operations/Tracking` para escribir el historial.
 *
 * Cualquier operación que mueva un envío (recepción, embarque, desembarque,
 * entrega) deja un `TrackingEvent` en la misma transacción. El historial es
 * append-only y vive en el subcontexto Tracking.
 */
interface TrackingRecorder
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        ShipmentSnapshot $shipment,
        string $eventType,
        ?int $agencyId = null,
        ?string $scanId = null,
        array $meta = [],
        ?string $occurredAt = null,
    ): void;
}
