<?php

namespace Src\Finance\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'document_type' => $this->document_type,
            'series' => $this->series,
            'next_number' => $this->next_number,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'issuer_ruc' => $this->issuer_ruc,
            'issuer_name' => $this->issuer_name,
            'issuer_trade_name' => $this->issuer_trade_name,
            'issuer_address' => $this->issuer_address,
            'issuer_ubigeo' => $this->issuer_ubigeo,
            'establishment_code' => $this->establishment_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
