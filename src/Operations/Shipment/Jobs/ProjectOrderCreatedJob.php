<?php

namespace Src\Operations\Shipment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Services\ProcessedEvents;

/**
 * Proyección de `order.created` hacia Operaciones.
 *
 * Crea la fila de `shipments` en estado `created` (la recepción la pasa a
 * `received`) y registra el primer evento de tracking. Es idempotente por
 * `order_id`: reenviar el evento actualiza los datos de la orden, no duplica.
 */
final class ProjectOrderCreatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProcessedEvents $processed,
        ShipmentDirectory $shipments,
        TrackingRecorder $tracking,
    ): void {
        $processed->once($this->outboxEventId, self::class, function () use ($shipments, $tracking): void {
            $orderId = (int) ($this->payload['order_id'] ?? 0);

            if ($orderId === 0) {
                return;
            }

            $shipment = Shipment::query()->where('order_id', $orderId)->lockForUpdate()->first();
            $created = $shipment === null;

            if ($shipment === null) {
                $shipment = Shipment::create([
                    'order_id' => $orderId,
                    'ticket_code' => (string) $this->payload['ticket_code'],
                    'ticket_number' => (string) $this->payload['ticket_number'],
                    'origin_agency_id' => (int) $this->payload['origin_agency_id'],
                    'destination_agency_id' => (int) $this->payload['destination_agency_id'],
                    'current_location_agency_id' => (int) $this->payload['origin_agency_id'],
                    'status' => Shipment::STATUS_CREATED,
                ]);
            } else {
                $shipment->update([
                    'ticket_code' => (string) $this->payload['ticket_code'],
                    'ticket_number' => (string) $this->payload['ticket_number'],
                    'origin_agency_id' => (int) $this->payload['origin_agency_id'],
                    'destination_agency_id' => (int) $this->payload['destination_agency_id'],
                ]);
            }

            if (! $created) {
                return;
            }

            $snapshot = $shipments->findById($shipment->id);

            if ($snapshot !== null) {
                $tracking->record(
                    shipment: $snapshot,
                    eventType: 'created',
                    agencyId: $snapshot->originAgencyId,
                    meta: ['source' => 'order.created'],
                    occurredAt: $this->payload['created_at'] ?? null,
                );
            }
        });
    }
}
