<?php

namespace Src\Fleet\Vehicle\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectOdometerRequest extends FormRequest
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
            'odometer_km' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
