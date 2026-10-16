<?php

namespace Src\Logistics\Order\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'status' => $this->status,
            'weight_kg' => $this->weight_kg,
            'total_amount' => $this->total_amount,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'origin_agency' => $this->origin_agency_id,
            'destination' => $this->destination_agency_id,
            'created_at' => $this->created_at,
        ];
    }
}
