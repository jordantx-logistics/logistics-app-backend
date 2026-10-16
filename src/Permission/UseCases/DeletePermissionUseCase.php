<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Spatie\Permission\Models\Permission;

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
