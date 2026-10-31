<?php

namespace Src\Fleet\Expense\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Expense\Models\Expense;

class ListExpensesRequest extends FormRequest
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
            'route_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', 'string', Rule::in(Expense::TYPES)],
            'status' => ['sometimes', 'string', 'in:registered,liquidated,rejected'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
