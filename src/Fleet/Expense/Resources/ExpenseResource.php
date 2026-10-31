<?php

namespace Src\Fleet\Expense\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'driver_id' => $this->driver_id,
            'vehicle_id' => $this->vehicle_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'liters' => $this->liters,
            'odometer_km' => $this->odometer_km,
            'has_receipt' => $this->receipt_path !== null,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'registered_by' => $this->registered_by,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
