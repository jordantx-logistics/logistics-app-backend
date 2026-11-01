<?php

namespace Src\Fleet\Route\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status,
            'planned_departure_at' => $this->planned_departure_at?->toIso8601String(),
            'planned_arrival_at' => $this->planned_arrival_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'computed_km' => $this->computed_km,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'segments' => RouteSegmentResource::collection($this->whenLoaded('segments')),
            'mileage_logs' => MileageLogResource::collection($this->whenLoaded('mileageLogs')),
        ];
    }
}
