<?php

namespace Src\Logistics\Location\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Logistics\Location\Rules\AgencyExists;

class AgencyCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $coverageId = $this->route('coverage')?->id;

        return [
            'agency_id' => ['required', 'integer', app(AgencyExists::class)],
            'location_id' => [
                'required',
                'integer',
                'exists:locations,id',
                // Una agencia cubre una ubicación una sola vez.
                Rule::unique('agency_coverages', 'location_id')
                    ->where(fn ($query) => $query->where('agency_id', $this->integer('agency_id')))
                    ->ignore($coverageId),
            ],
            'allows_pickup' => ['boolean'],
            'allows_delivery' => ['boolean'],
            'delivery_surcharge' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
