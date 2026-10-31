<?php

namespace Src\Fleet\Vehicle\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'type' => $this->type,
            'capacity_kg' => $this->capacity_kg,
            'capacity_m3' => $this->capacity_m3,
            'owner_agency_id' => $this->owner_agency_id,
            'status' => $this->status,
            'odometer_km' => $this->odometer_km,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
