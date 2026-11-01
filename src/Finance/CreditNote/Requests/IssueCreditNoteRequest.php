<?php

namespace Src\Finance\CreditNote\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Finance\CreditNote\Models\CreditNote;

class IssueCreditNoteRequest extends FormRequest
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
            'payment_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'in:'.implode(',', CreditNote::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
