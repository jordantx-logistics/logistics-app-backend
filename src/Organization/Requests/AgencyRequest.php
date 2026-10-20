<?php

namespace Src\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Organization\Rules\UbigeoExists;

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
            'province' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            // Ubigeo del distrito: lo usa el motor de tarifas (agencia → zona).
            'ubigeo' => ['nullable', 'string', 'size:6', app(UbigeoExists::class)],
            'is_active' => ['boolean'],
        ];
    }
}
