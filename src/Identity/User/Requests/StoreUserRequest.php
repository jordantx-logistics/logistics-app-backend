<?php

namespace Src\Identity\User\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de usuario por un administrador (UC-01). No hay registro público.
 *
 * La unicidad del email y la política de contraseña no son validación de forma:
 * son reglas del `RulesPipeline` (`USER_EMAIL_TAKEN`, `PASSWORD_TOO_WEAK`).
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'must_change_password' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'agency_ids' => ['nullable', 'array'],
            'agency_ids.*' => ['integer', Rule::exists('agencies', 'id')],
            'primary_agency_id' => ['nullable', 'integer'],
        ];
    }
}
