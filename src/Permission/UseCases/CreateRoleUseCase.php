<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Src\Permission\Models\Role;
use Src\Permission\Requests\CreateRoleRequest;

/**
 * UseCase para crear un nuevo rol.
 */
class CreateRoleUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Create a new role
     *
     * @param  CreateRoleRequest  $request  Validation request
     */
    public function execute(CreateRoleRequest $request): Role
    {
        $role = Role::create($request->validated());

        return $role;
    }
}
