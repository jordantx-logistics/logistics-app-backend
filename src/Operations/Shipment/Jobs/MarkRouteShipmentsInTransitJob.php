<?php

namespace Src\Operations\Shipment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Operations\Shipment\Models\EmbarkItem;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Services\ProcessedEvents;

/**
 * `route.started`: los envíos embarcados en esa ruta pasan a `in_transit`. El
 * enlace es `embarks.route_ref`; un embarque sin ruta no se toca. Un envío que
 * ya no está `embarked` (cancelado, desembarcado) se ignora.
 */
final class MarkRouteShipmentsInTransitJob implements ShouldQueue
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
            $ticketNumber = (string) ($this->payload['ticket_number'] ?? '');

            $shipmentIds = EmbarkItem::query()
                ->whereHas('embark', fn ($embark) => $embark->where('route_ref', $ticketNumber))
                ->pluck('shipment_id');

            foreach ($shipmentIds as $shipmentId) {
                $snapshot = $shipments->findById((int) $shipmentId);

                if ($snapshot === null || $snapshot->status !== Shipment::STATUS_EMBARKED) {
                    continue;
                }

                $moved = $shipments->transition(
                    shipmentId: $snapshot->id,
                    to: Shipment::STATUS_IN_TRANSIT,
                    from: Shipment::STATUS_EMBARKED,
                );

                $tracking->record(
                    shipment: $moved,
                    eventType: 'in_transit',
                    agencyId: $moved->originAgencyId,
                    meta: ['route_ref' => $ticketNumber, 'source' => 'route.started'],
                    occurredAt: $this->payload['started_at'] ?? null,
                );
            }
        });
    }
}
