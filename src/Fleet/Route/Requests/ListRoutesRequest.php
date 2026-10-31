<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRoutesRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'max:100'],
            'agency' => ['sometimes', 'integer', 'min:1'],
            'driver_id' => ['sometimes', 'integer', 'min:1'],
            'vehicle_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_number' => ['sometimes', 'string', 'max:20'],
            'departure_from' => ['sometimes', 'date'],
            'departure_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
