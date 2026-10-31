<?php

namespace Src\Finance\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
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
            'opening_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }
}
