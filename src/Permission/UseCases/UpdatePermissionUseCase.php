<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Src\Permission\Models\Permission;
use Src\Permission\Requests\UpdatePermissionRequest;

/**
 * UseCase para actualizar un permiso existente.
 */
class UpdatePermissionUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Update an existing permission
     *
     * @param  int  $id  Permission ID
     * @param  UpdatePermissionRequest  $request  Validation request
     */
    public function execute(int $id, UpdatePermissionRequest $request): Permission
    {
        $permission = Permission::findOrFail($id);
        $permission->update($request->validated());

        return $permission->fresh();
    }
}
