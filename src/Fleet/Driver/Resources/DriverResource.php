<?php

namespace Src\Fleet\Driver\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'license_number' => $this->license_number,
            'license_category' => $this->license_category,
            'license_expires_at' => $this->license_expires_at?->toDateString(),
            'phone' => $this->phone,
            'status' => $this->status,
            'home_agency_id' => $this->home_agency_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
