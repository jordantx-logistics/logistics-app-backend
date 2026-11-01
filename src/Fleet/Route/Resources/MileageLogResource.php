<?php

namespace Src\Fleet\Route\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MileageLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'vehicle_id' => $this->vehicle_id,
            'odometer_km' => $this->odometer_km,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'recorded_by' => $this->recorded_by,
            'has_photo' => $this->photo_path !== null,
        ];
    }
}
