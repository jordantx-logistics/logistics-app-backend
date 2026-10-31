<?php

namespace Src\Finance\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Finance\Payment\Models\Payment;

class RegisterPaymentRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'currency' => ['sometimes', 'string', 'size:3', 'in:PEN'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', Payment::METHODS)],
            'document_type' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Payment::DOCUMENT_TYPES)],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
