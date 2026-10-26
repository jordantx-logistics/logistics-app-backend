<?php

namespace Src\Operations\Tracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shipment_id' => $this->resource['shipment']->id,
            'order_id' => $this->resource['shipment']->orderId,
            'ticket_code' => $this->resource['shipment']->ticketCode,
            'ticket_number' => $this->resource['shipment']->ticketNumber,
            'status' => $this->resource['shipment']->status,
            'origin_agency_id' => $this->resource['shipment']->originAgencyId,
            'destination_agency_id' => $this->resource['shipment']->destinationAgencyId,
            'current_location_agency_id' => $this->resource['shipment']->currentLocationAgencyId,
            'delivered_at' => $this->resource['shipment']->deliveredAt,
            'events' => $this->resource['events']->map(fn ($event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'agency_id' => $event->agency_id,
                'scan_id' => $event->scan_id,
                'meta' => $event->meta,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
