<?php

namespace Src\Fleet\Driver\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Driver\Models\Driver;

class UpdateDriverRequest extends FormRequest
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
            'license_number' => ['sometimes', 'string', 'max:20', Rule::unique('drivers', 'license_number')->ignore((int) $this->route('driver'))],
            'license_category' => ['sometimes', 'string', 'max:8'],
            'license_expires_at' => ['sometimes', 'date_format:Y-m-d'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'string', Rule::in(Driver::STATUSES)],
        ];
    }
}
