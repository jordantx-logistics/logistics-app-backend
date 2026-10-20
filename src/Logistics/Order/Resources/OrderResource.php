<?php

namespace Src\Logistics\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Las claves de otros contextos llevan sufijo `_id` (`sender_id`,
        // `origin_agency_id`...): son ids sueltos, sin relación Eloquent ni FK
        // (ADR-0006).
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'status' => $this->status,
            'version' => $this->version,
            'service_type' => $this->service_type,
            'payment_responsibility' => $this->payment_responsibility,
            'description' => $this->description,
            'weight_kg' => $this->weight_kg,
            'volume_m3' => $this->volume_m3,
            'chargeable_weight_kg' => $this->chargeable_weight_kg,
            'declared_value' => $this->declared_value,
            'tariff_id' => $this->tariff_id,
            'pricing_snapshot' => $this->pricing_snapshot,
            'total_amount' => $this->total_amount,
            'delivery_type' => $this->delivery_type,
            'delivery_location_id' => $this->delivery_location_id,
            'recipient_address' => $this->recipient_address,
            'delivery_scheduled_at' => $this->delivery_scheduled_at?->toIso8601String(),
            'requires_signature' => $this->requires_signature,
            'delivery_attempts' => $this->delivery_attempts,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($entry): array => [
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'reason' => $entry->reason,
                'changed_by' => $entry->changed_by,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
