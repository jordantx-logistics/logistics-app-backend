<?php

namespace Src\Identity\User\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de los datos de un usuario (no del estado: eso es UC-06 y su propio
 * endpoint).
 */
class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:150'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }
}
