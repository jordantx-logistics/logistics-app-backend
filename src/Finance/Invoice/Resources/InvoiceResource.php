<?php

namespace Src\Finance\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'order_id' => $this->order_id,
            'payment_id' => $this->payment_id,
            'agency_id' => $this->agency_id,
            'invoice_series_id' => $this->invoice_series_id,
            'document_type' => $this->document_type,
            'series' => $this->series,
            'number' => $this->number,
            'customer_document_type' => $this->customer_document_type,
            'customer_document_number' => $this->customer_document_number,
            'customer_name' => $this->customer_name,
            'taxable_amount' => $this->taxable_amount,
            'igv' => $this->igv,
            'total' => $this->total,
            'currency' => $this->currency,
            'status' => $this->status,
            'sunat_code' => $this->sunat_code,
            'sunat_message' => $this->sunat_message,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'downloads' => [
                'xml' => $this->xml_path !== null ? "/api/v1/finance/invoices/{$this->id}/download/xml" : null,
                'pdf' => $this->pdf_path !== null ? "/api/v1/finance/invoices/{$this->id}/download/pdf" : null,
                'cdr' => $this->cdr_path !== null ? "/api/v1/finance/invoices/{$this->id}/download/cdr" : null,
            ],
        ];
    }
}
