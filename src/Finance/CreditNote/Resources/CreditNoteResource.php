<?php

namespace Src\Finance\CreditNote\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'invoice_id' => $this->invoice_id,
            'agency_id' => $this->agency_id,
            'cash_session_id' => $this->cash_session_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'note' => $this->note,
            'status' => $this->status,
            'sunat_code' => $this->sunat_code,
            'sunat_message' => $this->sunat_message,
            'created_by' => $this->created_by,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'downloads' => [
                'xml' => $this->xml_path !== null ? "/api/v1/finance/credit-notes/{$this->id}/download/xml" : null,
                'pdf' => $this->pdf_path !== null ? "/api/v1/finance/credit-notes/{$this->id}/download/pdf" : null,
                'cdr' => $this->cdr_path !== null ? "/api/v1/finance/credit-notes/{$this->id}/download/cdr" : null,
            ],
        ];
    }
}
