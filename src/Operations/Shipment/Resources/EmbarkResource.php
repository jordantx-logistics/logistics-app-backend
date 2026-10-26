<?php

namespace Src\Operations\Shipment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'route_ref' => $this->route_ref,
            'departed_at' => $this->departed_at?->toIso8601String(),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : 0),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'shipment_id' => $item->shipment_id,
                'ticket_code' => $item->ticket_code,
                'scan_id' => $item->scan_id,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
