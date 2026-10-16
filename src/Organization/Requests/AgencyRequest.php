<?php

namespace Src\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $agencyId = $this->route('agency') ? $this->route('agency')->id : null;

        return [
            'code' => ['required', 'string', 'max:10', 'unique:agencies,code,'.$agencyId],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
