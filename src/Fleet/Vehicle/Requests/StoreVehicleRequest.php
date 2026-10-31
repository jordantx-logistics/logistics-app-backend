<?php

namespace Src\Fleet\Vehicle\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Vehicle\Models\Vehicle;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** La placa se guarda normalizada: sin espacios ni guiones y en mayúsculas. */
    protected function prepareForValidation(): void
    {
        if ($this->filled('plate')) {
            $this->merge(['plate' => strtoupper((string) preg_replace('/[\s-]+/', '', (string) $this->input('plate')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plate' => ['required', 'string', 'regex:/^[A-Z0-9]{5,10}$/', 'unique:vehicles,plate'],
            'type' => ['required', 'string', Rule::in(Vehicle::TYPES)],
            'capacity_kg' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'capacity_m3' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'owner_agency_id' => ['required', 'integer', 'min:1'],
            'odometer_km' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
