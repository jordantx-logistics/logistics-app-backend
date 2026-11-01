<?php

namespace Src\Finance\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPaymentsRequest extends FormRequest
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
            'order_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:120'],
            'payment_method' => ['sometimes', 'string', 'max:20'],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'ticket_number' => ['sometimes', 'string', 'max:30'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
