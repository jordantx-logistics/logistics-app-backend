<?php

namespace Src\Fleet\Driver\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'min:1', 'unique:drivers,user_id'],
            'license_number' => ['required', 'string', 'max:20', 'unique:drivers,license_number'],
            'license_category' => ['required', 'string', 'max:8'],
            'license_expires_at' => ['required', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:20'],
            'home_agency_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
