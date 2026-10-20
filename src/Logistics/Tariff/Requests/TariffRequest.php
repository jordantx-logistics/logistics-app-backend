<?php

namespace Src\Logistics\Tariff\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Logistics\Tariff\Rules\LocationExists;
use Src\Logistics\Tariff\Rules\ZoneExists;

class TariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tariffId = $this->route('tariff')?->id;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('tariffs', 'code')->ignore($tariffId)],
            'name' => ['required', 'string', 'max:150'],
            'service_type' => ['required', 'string', Rule::in(Tariff::SERVICE_TYPES)],
            'scope_type' => ['required', 'string', Rule::in(Tariff::SCOPE_TYPES)],

            // Zonas y ubicaciones son de otro contexto: se validan por puerto.
            'origin_zone_id' => ['nullable', 'integer', app(ZoneExists::class)],
            'destination_zone_id' => ['nullable', 'integer', app(ZoneExists::class)],
            'origin_location_id' => ['nullable', 'integer', app(LocationExists::class)],
            'destination_location_id' => ['nullable', 'integer', app(LocationExists::class)],

            'base_price' => ['required', 'numeric', 'min:0'],
            'price_per_kg' => ['required', 'numeric', 'min:0'],
            'price_per_m3' => ['nullable', 'numeric', 'min:0'],
            'min_charge' => ['required', 'numeric', 'min:0'],
            'max_weight_kg' => ['required', 'numeric', 'gt:0'],
            'volumetric_factor' => ['nullable', 'integer', 'min:0'],
            'priority' => ['nullable', 'integer'],

            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date'],
            'is_active' => ['boolean'],

            'brackets' => ['nullable', 'array'],
            'brackets.*.from_kg' => ['required', 'numeric', 'min:0'],
            'brackets.*.to_kg' => ['required', 'numeric', 'min:0'],
            'brackets.*.fixed_fee' => ['required', 'numeric', 'min:0'],
            'brackets.*.price_per_kg' => ['required', 'numeric', 'min:0'],
        ];
    }
}
