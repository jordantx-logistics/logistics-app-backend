<?php

namespace Src\Logistics\Location\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Logistics\Location\Models\AgencyCoverage;

/**
 * @mixin AgencyCoverage
 */
class AgencyCoverageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'location_id' => $this->location_id,
            'location' => LocationResource::make($this->whenLoaded('location')),
            'allows_pickup' => $this->allows_pickup,
            'allows_delivery' => $this->allows_delivery,
            'delivery_surcharge' => $this->delivery_surcharge,
            'is_active' => $this->is_active,
        ];
    }
}
