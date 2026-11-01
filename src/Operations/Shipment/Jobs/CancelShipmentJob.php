<?php

namespace Src\Operations\Shipment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Services\ProcessedEvents;

/**
 * Consume `order.cancelled` y cancela el envío si todavía no salió a reparto.
 *
 * Si el envío ya está en tránsito, entregado, devuelto o cancelado, el evento
 * se ignora (no se retrocede el estado). Idempotente por el outbox.
 */
final class CancelShipmentJob implements ShouldQueue
{
    use Queueable;

    private const CANCELLABLE = [
        Shipment::STATUS_CREATED,
        Shipment::STATUS_RECEIVED,
        Shipment::STATUS_EMBARKED,
    ];

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
            $shipment = Shipment::query()->where('order_id', $orderId)->first();

            if ($shipment === null || ! in_array($shipment->status, self::CANCELLABLE, true)) {
                return;
            }

            $snapshot = $shipments->transition(
                $shipment->id,
                Shipment::STATUS_CANCELLED,
                from: $shipment->status,
            );

            $tracking->record(
                shipment: $snapshot,
                eventType: 'cancelled',
                agencyId: $snapshot->currentLocationAgencyId ?? $snapshot->originAgencyId,
                meta: [
                    'source' => 'order.cancelled',
                    'reason' => $this->payload['reason'] ?? null,
                ],
                occurredAt: $this->payload['cancelled_at'] ?? null,
            );
        });
    }
}
