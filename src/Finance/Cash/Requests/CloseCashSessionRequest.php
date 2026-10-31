<?php

namespace Src\Finance\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
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
            'closing_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'count' => ['sometimes', 'array'],
            'count.*.denomination' => ['required_with:count', 'numeric', 'min:0'],
            'count.*.quantity' => ['required_with:count', 'integer', 'min:0'],
        ];
    }
}
