<?php

namespace Src\Finance\Cash\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cash_session_id' => $this->cash_session_id,
            'status' => $this->status,
            'expected_amount' => $this->expected_amount,
            'closing_amount' => $this->closing_amount,
            'difference' => $this->difference,
            'requested_by' => $this->requested_by,
            'approved_by' => $this->approved_by,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'report_url' => $this->report_path !== null
                ? "/api/v1/finance/cash-closures/{$this->id}/report"
                : null,
        ];
    }
}
