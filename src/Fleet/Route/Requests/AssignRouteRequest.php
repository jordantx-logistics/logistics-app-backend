<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignRouteRequest extends FormRequest
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
            'driver_id' => ['required', 'integer', 'min:1'],
            'vehicle_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
