<?php

namespace Src\Identity\Role\UseCases;

use App\Models\User;
use Src\Identity\Role\Models\Permission;

/**
 * UseCase para eliminar un permiso.
 */
class DeletePermissionUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Delete a permission
     *
     * @param  int  $id  Permission ID
     */
    public function execute(int $id): void
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();
    }
}
