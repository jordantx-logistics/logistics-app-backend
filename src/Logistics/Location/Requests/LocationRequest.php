<?php

namespace Src\Logistics\Location\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $locationId = $this->route('location')?->id;

        return [
            'ubigeo' => ['required', 'string', 'size:6', Rule::unique('locations', 'ubigeo')->ignore($locationId)],
            'department' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:150'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'is_active' => ['boolean'],
        ];
    }
}
