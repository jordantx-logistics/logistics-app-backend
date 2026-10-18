<?php

namespace Src\Permission\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Src\Permission\Models\Permission;

/**
 * FormRequest para validar la asignación de permisos a un rol.
 */
class AssignPermissionsToRoleRequest extends FormRequest
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
            'permission_ids' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    $existingPermissions = Permission::pluck('id');
                    foreach ($value as $id) {
                        if (! $existingPermissions->contains($id)) {
                            $fail("El permiso con ID {$id} no existe.");
                        }
                    }
                },
            ],
        ];
    }
}
