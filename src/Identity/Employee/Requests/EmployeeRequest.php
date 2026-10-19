<?php

namespace Src\Identity\Employee\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Identity\Employee\Models\Employee;

/**
 * Forma de alta y edición de un empleado (UC-08).
 *
 * La unicidad del documento y la validez de la agencia **no** son validación de
 * forma, sino reglas de negocio del `RulesPipeline`
 * (`EMPLOYEE_DOCUMENT_TAKEN`, `EMPLOYEE_AGENCY_INVALID`). Aquí solo se comprueba
 * que el payload tiene la forma correcta.
 */
class EmployeeRequest extends FormRequest
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
            'person_id' => ['nullable', 'integer', 'min:1'],
            'document_type' => ['required', Rule::in(Employee::DOCUMENT_TYPES)],
            'document_number' => ['required', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['required', 'string', 'max:80'],
            'agency_id' => ['required', 'integer', 'min:1'],
            'hired_at' => ['required', 'date'],
            'terminated_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
            'status' => ['nullable', Rule::in(Employee::STATUSES)],
        ];
    }
}
