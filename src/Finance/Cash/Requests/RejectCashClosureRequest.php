<?php

namespace Src\Finance\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectCashClosureRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
