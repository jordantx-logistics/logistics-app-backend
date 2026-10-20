<?php

namespace Src\Identity\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'agency_id' => $this->agency_id,
            'type' => $this->type,
            'marked_at' => $this->marked_at?->toIso8601String(),
            'source' => $this->source,
            'device_id' => $this->device_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'ip_address' => $this->ip_address,
            'status' => $this->status,
            'idempotency_key' => $this->idempotency_key,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
