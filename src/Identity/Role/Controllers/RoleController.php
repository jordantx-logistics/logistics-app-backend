<?php

namespace Src\Identity\Role\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Identity\Role\Requests\AssignPermissionsToRoleRequest;
use Src\Identity\Role\Requests\AssignRoleToUserRequest;
use Src\Identity\Role\Requests\CreateRoleRequest;
use Src\Identity\Role\Requests\UpdateRoleRequest;
use Src\Identity\Role\Resources\RoleResource;
use Src\Identity\Role\UseCases\AssignPermissionsToRoleUseCase;
use Src\Identity\Role\UseCases\AssignRoleToUserUseCase;
use Src\Identity\Role\UseCases\CreateRoleUseCase;
use Src\Identity\Role\UseCases\DeleteRoleUseCase;
use Src\Identity\Role\UseCases\ListRolesUseCase;
use Src\Identity\Role\UseCases\UpdateRoleUseCase;

/**
 * @group Permission - Roles
 *
 * APIs para gestionar roles del sistema.
 */
class RoleController extends Controller
{
    /**
     * List all roles
     *
     * El controlador envuelve la colección (`data.roles`), y eso no se puede
     * inferir de un `@apiResource`: la forma se documenta a mano para que el
     * contrato no mienta (`spec/08-calidad/02-contrato-openapi-frontend.md`).
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Roles retrieved successfully.",
     *   "data": {
     *     "roles": [
     *       {
     *         "id": 1,
     *         "name": "admin",
     *         "guard_name": "web",
     *         "permissions": ["orders.view", "orders.create"],
     *         "created_at": "2026-10-18T20:18:04.000000Z",
     *         "updated_at": "2026-10-18T20:18:04.000000Z"
     *       }
     *     ]
     *   }
     * }
     */
    public function index(ListRolesUseCase $useCase): JsonResponse
    {
        $roles = $useCase->execute();

        return $this->success(
            data: ['roles' => RoleResource::collection($roles)],
            message: 'Roles retrieved successfully.'
        );
    }

    /**
     * Create a new role
     *
     * @apiResource Src\Identity\Role\Resources\RoleResource status=201
     *
     * @apiResourceModel Src\Identity\Role\Models\Role
     */
    public function store(CreateRoleRequest $request, CreateRoleUseCase $useCase): JsonResponse
    {
        $role = $useCase->execute($request);

        return $this->success(
            data: RoleResource::make($role),
            message: 'Role created successfully.',
            statusCode: 201
        );
    }

    /**
     * Retrieve a specific role
     *
     * @apiResource Src\Identity\Role\Resources\RoleResource
     *
     * @apiResourceModel Src\Identity\Role\Models\Role
     */
    public function show(int $id, ListRolesUseCase $useCase): JsonResponse
    {
        $role = $useCase->findOrFail($id);

        return $this->success(
            data: RoleResource::make($role),
            message: 'Role retrieved successfully.'
        );
    }

    /**
     * Update an existing role
     *
     * @apiResource Src\Identity\Role\Resources\RoleResource
     *
     * @apiResourceModel Src\Identity\Role\Models\Role
     */
    public function update(int $id, UpdateRoleRequest $request, UpdateRoleUseCase $useCase): JsonResponse
    {
        $role = $useCase->execute($id, $request);

        return $this->success(
            data: RoleResource::make($role),
            message: 'Role updated successfully.'
        );
    }

    /**
     * Delete a role
     */
    public function destroy(int $id, DeleteRoleUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return $this->success(
            message: 'Role deleted successfully.'
        );
    }

    /**
     * Assign permissions to a role
     *
     * Sustituye los permisos del rol por los que llegan (`syncPermissions`).
     *
     * @bodyParam permission_ids integer[] required Ids de los permisos del catálogo. Example: [1, 2, 3]
     *
     * @apiResource Src\Identity\Role\Resources\RoleResource
     *
     * @apiResourceModel Src\Identity\Role\Models\Role
     */
    public function assignPermissions(int $id, AssignPermissionsToRoleRequest $request, AssignPermissionsToRoleUseCase $useCase): JsonResponse
    {
        $role = $useCase->execute($id, $request);

        return $this->success(
            data: RoleResource::make($role),
            message: 'Permissions assigned to role successfully.'
        );
    }

    /**
     * Assign a role to a user
     *
     * @apiResourceModel Src\Identity\Role\Models\Role
     */
    public function assignRole(int $userId, AssignRoleToUserRequest $request, AssignRoleToUserUseCase $useCase): JsonResponse
    {
        $user = $useCase->execute($userId, $request);

        return $this->success(
            data: ['user' => $user],
            message: 'Role assigned to user successfully.'
        );
    }
}
