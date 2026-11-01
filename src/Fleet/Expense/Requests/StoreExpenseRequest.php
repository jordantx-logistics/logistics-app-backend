<?php

namespace Src\Fleet\Expense\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Expense\Models\Expense;

class StoreExpenseRequest extends FormRequest
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
            'route_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(Expense::TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'liters' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'odometer_km' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
