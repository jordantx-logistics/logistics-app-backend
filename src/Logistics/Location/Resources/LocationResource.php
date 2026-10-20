<?php

namespace Src\Logistics\Location\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Logistics\Location\Models\Location;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ubigeo' => $this->ubigeo,
            'department' => $this->department,
            'province' => $this->province,
            'district' => $this->district,
            'zone_id' => $this->zone_id,
            'zone' => ZoneResource::make($this->whenLoaded('zone')),
            'is_active' => $this->is_active,
        ];
    }
}
