<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Src\Permission\Models\Permission;
use Src\Permission\Models\Role;
use Src\Permission\Requests\AssignPermissionsToRoleRequest;

/**
 * UseCase para asignar permisos a un rol.
 */
class AssignPermissionsToRoleUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Assign permissions to a role
     *
     * @param  int  $roleId  Role ID
     * @param  AssignPermissionsToRoleRequest  $request  Validation request
     */
    public function execute(int $roleId, AssignPermissionsToRoleRequest $request): Role
    {
        $role = Role::findOrFail($roleId);
        $permissions = Permission::whereIn('id', $request->validated('permission_ids'))->get();
        $role->syncPermissions($permissions);

        return $role->fresh();
    }
}
