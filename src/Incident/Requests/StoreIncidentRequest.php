<?php

namespace Src\Incident\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Incident\Models\Incident;

class StoreIncidentRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(Incident::TYPES)],
            'priority' => ['sometimes', 'string', Rule::in(Incident::PRIORITIES)],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
