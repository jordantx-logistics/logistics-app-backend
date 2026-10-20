<?php

namespace Src\Logistics\Location\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $zoneId = $this->route('zone')?->id;

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('zones', 'code')->ignore($zoneId)],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
