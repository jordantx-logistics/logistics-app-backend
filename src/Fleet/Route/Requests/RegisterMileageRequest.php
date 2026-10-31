<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterMileageRequest extends FormRequest
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
            'recorded_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
