<?php

namespace Src\Finance\Payment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Finance\CreditNote\Resources\CreditNoteResource;
use Src\Finance\Invoice\Resources\InvoiceResource;
use Src\Finance\Void\Resources\VoidResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'ticket_code' => $this->ticket_code,
            'order_id' => $this->order_id,
            'cash_session_id' => $this->cash_session_id,
            'agency_id' => $this->agency_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'invoice' => new InvoiceResource($this->whenLoaded('currentInvoice')),
            'credit_notes' => CreditNoteResource::collection($this->whenLoaded('creditNotes')),
            'voids' => VoidResource::collection($this->whenLoaded('voids')),
        ];
    }
}
