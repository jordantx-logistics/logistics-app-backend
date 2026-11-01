<?php

namespace Src\Fleet\Vehicle\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Vehicle\Models\Vehicle;

class UpdateVehicleStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(Vehicle::MANUAL_STATUSES)],
        ];
    }
}
