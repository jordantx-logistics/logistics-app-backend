<?php

namespace Src\Finance\Invoice\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Finance\Invoice\Models\Invoice;

class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'max:120'],
            'document_type' => ['sometimes', 'string', 'in:'.implode(',', Invoice::DOCUMENT_TYPES)],
            'order_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_number' => ['sometimes', 'string', 'max:30'],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'issued_from' => ['sometimes', 'date'],
            'issued_to' => ['sometimes', 'date', 'after_or_equal:issued_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
