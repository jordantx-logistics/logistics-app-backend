<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Src\Permission\Models\Role;
use Src\Permission\Requests\AssignRoleToUserRequest;

/**
 * UseCase para asignar un rol a un usuario.
 */
class AssignRoleToUserUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Assign a role to a user
     *
     * @param  int  $userId  User ID
     * @param  AssignRoleToUserRequest  $request  Validation request
     */
    public function execute(int $userId, AssignRoleToUserRequest $request): User
    {
        $user = User::findOrFail($userId);
        $roles = Role::whereIn('id', $request->validated('role_ids'))->get();
        $user->syncRoles($roles);

        return $user->fresh();
    }
}
