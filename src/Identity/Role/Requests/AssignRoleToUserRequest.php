<?php

namespace Src\Identity\Role\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Role\Models\Role;

/**
 * FormRequest para validar la asignación de un rol a un usuario.
 */
class AssignRoleToUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_ids' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    $existingRoles = Role::pluck('id');
                    foreach ($value as $id) {
                        if (! $existingRoles->contains($id)) {
                            $fail("El rol con ID {$id} no existe.");
                        }
                    }
                },
            ],
        ];
    }
}
