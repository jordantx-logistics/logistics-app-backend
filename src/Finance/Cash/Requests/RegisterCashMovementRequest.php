<?php

namespace Src\Finance\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Finance\Cash\Models\CashMovement;

class RegisterCashMovementRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:'.CashMovement::TYPE_EXPENSE.','.CashMovement::TYPE_WITHDRAWAL],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'note' => ['required', 'string', 'max:255'],
            'reference_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'reference_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
