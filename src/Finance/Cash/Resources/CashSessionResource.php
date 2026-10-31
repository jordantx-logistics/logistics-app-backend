<?php

namespace Src\Finance\Cash\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'cashier_id' => $this->cashier_id,
            'status' => $this->status,
            'opening_amount' => $this->opening_amount,
            'expected_amount' => $this->expected_amount,
            'closing_amount' => $this->closing_amount,
            'difference' => $this->difference,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'movements' => CashMovementResource::collection($this->whenLoaded('movements')),
            'closures' => CashClosureResource::collection($this->whenLoaded('closures')),
        ];
    }
}
