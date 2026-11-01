<?php

namespace Src\Shared\Contracts;

use Src\Shared\DTOs\ShipmentSnapshot;

/**
 * Puerto hacia `Operations/Shipment`.
 *
 * Reception, Inventory, Tracking y Delivery leen y mueven el estado operativo
 * sin tocar la tabla `shipments` ni importar su modelo. El adaptador que conoce
 * Eloquent vive en `Src\Operations\Shipment\Adapters` y se enlaza en el
 * composition root.
 */
interface ShipmentDirectory
{
    public function findById(int $shipmentId): ?ShipmentSnapshot;

    public function findByTicketCode(string $ticketCode): ?ShipmentSnapshot;

    /**
     * @param  list<int>  $shipmentIds
     * @return array<int, ShipmentSnapshot> indexado por id
     */
    public function findMany(array $shipmentIds): array;

    /**
     * Aplica una transición de la máquina de estados del envío.
     *
     * Si el envío ya está en `$to` devuelve la foto actual (idempotencia de los
     * eventos y de los reintentos). Una transición no permitida lanza
     * `BusinessRuleException` con `INVALID_SHIPMENT_TRANSITION`.
     *
     * `$from` activa la comprobación de estado esperado **bajo el bloqueo de
     * fila**: si otro operario movió el envío entre la lectura y la escritura,
     * la transición falla en vez de aplicar dos veces el mismo efecto. Es lo
     * que evita que dos embarques simultáneos carguen el mismo paquete.
     */
    public function transition(
        int $shipmentId,
        string $to,
        ?int $currentLocationAgencyId = null,
        ?string $from = null,
    ): ShipmentSnapshot;
}
