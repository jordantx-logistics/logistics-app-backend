<?php

namespace Src\Operations\Delivery\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'ticket_code' => $this->ticket_code,
            'ticket_number' => $this->ticket_number,
            'agency_id' => $this->agency_id,
            'courier_id' => $this->courier_id,
            'status' => $this->status,
            'attempt' => $this->attempt,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'recipient_name' => $this->recipient_name,
            'recipient_document' => $this->recipient_document,
            'evidence' => $this->whenLoaded('evidence', fn () => $this->evidence === null ? null : [
                'signature_path' => $this->evidence->signature_path,
                'photo_path' => $this->evidence->photo_path,
                'latitude' => $this->evidence->latitude,
                'longitude' => $this->evidence->longitude,
                'captured_at' => $this->evidence->captured_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
