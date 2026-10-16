<?php

namespace Src\Permission\UseCases;

use App\Models\User;
use Src\Permission\Models\Permission;
use Src\Permission\Requests\CreatePermissionRequest;

/**
 * UseCase para crear un nuevo permiso.
 */
class CreatePermissionUseCase
{
    public function __construct(
        private readonly User $currentUser
    ) {}

    /**
     * Create a new permission
     *
     * @param  CreatePermissionRequest  $request  Validation request
     */
    public function execute(CreatePermissionRequest $request): Permission
    {
        $permission = Permission::create($request->validated());

        return $permission;
    }
}
