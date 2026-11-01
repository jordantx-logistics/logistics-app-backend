<?php

namespace Src\Fleet\Expense\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Expense\Models\Expense;

class LiquidateExpensesRequest extends FormRequest
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
            'expense_ids' => ['required', 'array', 'min:1', 'max:100'],
            'expense_ids.*' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', Rule::in([Expense::STATUS_LIQUIDATED, Expense::STATUS_REJECTED])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
