<?php

namespace Src\Operations\Shipment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'ticket_code' => $this->ticket_code,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'current_location_agency_id' => $this->current_location_agency_id,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
