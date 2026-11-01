<?php

namespace Src\Incident\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Incident\Models\Incident;

class ListIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El navegador envía `overdue=true`, que la regla `boolean` rechaza.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('overdue')) {
            $this->merge(['overdue' => filter_var($this->input('overdue'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(Incident::STATUSES)],
            'type' => ['sometimes', 'string', Rule::in(Incident::TYPES)],
            'priority' => ['sometimes', 'string', Rule::in(Incident::PRIORITIES)],
            'order_id' => ['sometimes', 'integer', 'min:1'],
            'ticket_code' => ['sometimes', 'string', 'size:8'],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
            'overdue' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
