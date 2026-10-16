<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Src\Permission\Requests\UpdateRoleRequest;

/**
 * UseCase para actualizar un rol existente.
 */
class UpdateRoleUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Update an existing role
     *
     * @param  int  $id  Role ID
     * @param  UpdateRoleRequest  $request  Validation request
     */
    public function execute(int $id, UpdateRoleRequest $request): Role
    {
        $role = Role::findOrFail($id);
        $role->update($request->validated());

        return $role->fresh();
    }
}
