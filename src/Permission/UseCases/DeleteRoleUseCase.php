<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * UseCase para eliminar un rol.
 */
class DeleteRoleUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Delete a role
     *
     * @param  int  $id  Role ID
     */
    public function execute(int $id): void
    {
        $role = Role::findOrFail($id);
        $role->delete();
    }
}
