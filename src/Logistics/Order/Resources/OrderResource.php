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
        // (ADR-0006). Los nombres de cliente y agencia llegan en la Fase 3, con
        // el payload ampliado de `order.created`.
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'status' => $this->status,
            'weight_kg' => $this->weight_kg,
            'total_amount' => $this->total_amount,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'origin_agency_id' => $this->origin_agency_id,
            'destination_agency_id' => $this->destination_agency_id,
            'created_at' => $this->created_at,
        ];
    }
}
