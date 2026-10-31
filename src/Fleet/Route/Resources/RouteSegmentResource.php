<?php

namespace Src\Fleet\Route\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'sequence' => $this->sequence,
            'from_agency_id' => $this->from_agency_id,
            'to_agency_id' => $this->to_agency_id,
            'distance_km' => $this->distance_km,
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
