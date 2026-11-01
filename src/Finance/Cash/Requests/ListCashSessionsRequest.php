<?php

namespace Src\Finance\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListCashSessionsRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:open,closed'],
            'cashier_id' => ['sometimes', 'integer', 'min:1'],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'opened_from' => ['sometimes', 'date'],
            'opened_to' => ['sometimes', 'date', 'after_or_equal:opened_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
