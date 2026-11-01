<?php

namespace Src\Incident\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'ticket_code' => $this->ticket_code,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'type' => $this->type,
            'priority' => $this->priority,
            'status' => $this->status,
            'description' => $this->description,
            'assigned_to' => $this->assigned_to,
            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
