<?php

namespace Src\Operations\Tracking\Adapters;

use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\DTOs\ShipmentSnapshot;

class EloquentTrackingRecorder implements TrackingRecorder
{
    public function record(
        ShipmentSnapshot $shipment,
        string $eventType,
        ?int $agencyId = null,
        ?string $scanId = null,
        array $meta = [],
        ?string $occurredAt = null,
    ): void {
        TrackingEvent::create([
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->orderId,
            'event_type' => $eventType,
            'status' => $shipment->status,
            'agency_id' => $agencyId,
            'scan_id' => $scanId,
            'meta' => $meta,
            'created_at' => $occurredAt ?? now(),
        ]);
    }
}
