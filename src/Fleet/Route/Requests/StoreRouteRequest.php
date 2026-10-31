<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRouteRequest extends FormRequest
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
            'origin_agency_id' => ['required', 'integer', 'min:1'],
            'destination_agency_id' => ['required', 'integer', 'min:1', 'different:origin_agency_id'],
            'planned_departure_at' => ['required', 'date'],
            'planned_arrival_at' => ['nullable', 'date', 'after:planned_departure_at'],
            'segments' => ['required', 'array', 'min:1', 'max:50'],
            'segments.*.from_agency_id' => ['required', 'integer', 'min:1'],
            'segments.*.to_agency_id' => ['required', 'integer', 'min:1'],
            'segments.*.distance_km' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
