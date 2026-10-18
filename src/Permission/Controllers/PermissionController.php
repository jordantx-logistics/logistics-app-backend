<?php

namespace Src\Permission\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Permission\Requests\CreatePermissionRequest;
use Src\Permission\Requests\UpdatePermissionRequest;
use Src\Permission\Resources\PermissionResource;
use Src\Permission\UseCases\CreatePermissionUseCase;
use Src\Permission\UseCases\DeletePermissionUseCase;
use Src\Permission\UseCases\ListPermissionsUseCase;
use Src\Permission\UseCases\UpdatePermissionUseCase;

/**
 * @group Permission - Permisos
 *
 * APIs para gestionar permisos del sistema.
 */
class PermissionController extends Controller
{
    /**
     * List all permissions
     *
     * El controlador envuelve la colección (`data.permissions`), y eso no se
     * puede inferir de un `@apiResource`: la forma se documenta a mano para que
     * el contrato no mienta (`spec/08-calidad/02-contrato-openapi-frontend.md`).
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Permissions retrieved successfully.",
     *   "data": {
     *     "permissions": [
     *       {
     *         "id": 1,
     *         "name": "orders.view",
     *         "guard_name": "web",
     *         "created_at": "2026-10-18T20:18:04.000000Z",
     *         "updated_at": "2026-10-18T20:18:04.000000Z"
     *       }
     *     ]
     *   }
     * }
     */
    public function index(ListPermissionsUseCase $useCase): JsonResponse
    {
        $permissions = $useCase->execute();

        return $this->success(
            data: ['permissions' => PermissionResource::collection($permissions)],
            message: 'Permissions retrieved successfully.'
        );
    }

    /**
     * Create a new permission
     *
     * @apiResource Src\Permission\Resources\PermissionResource
     *
     * @apiResourceModel Src\Permission\Models\Permission
     */
    public function store(CreatePermissionRequest $request, CreatePermissionUseCase $useCase): JsonResponse
    {
        $permission = $useCase->execute($request);

        return $this->success(
            data: PermissionResource::make($permission),
            message: 'Permission created successfully.',
            statusCode: 201
        );
    }

    /**
     * Retrieve a specific permission
     *
     * @apiResource Src\Permission\Resources\PermissionResource
     *
     * @apiResourceModel Src\Permission\Models\Permission
     */
    public function show(int $id, ListPermissionsUseCase $useCase): JsonResponse
    {
        $permission = $useCase->findOrFail($id);

        return $this->success(
            data: PermissionResource::make($permission),
            message: 'Permission retrieved successfully.'
        );
    }

    /**
     * Update an existing permission
     *
     * @apiResource Src\Permission\Resources\PermissionResource
     *
     * @apiResourceModel Src\Permission\Models\Permission
     */
    public function update(int $id, UpdatePermissionRequest $request, UpdatePermissionUseCase $useCase): JsonResponse
    {
        $permission = $useCase->execute($id, $request);

        return $this->success(
            data: PermissionResource::make($permission),
            message: 'Permission updated successfully.'
        );
    }

    /**
     * Delete a permission
     */
    public function destroy(int $id, DeletePermissionUseCase $useCase): JsonResponse
    {
        $useCase->execute($id);

        return $this->success(
            message: 'Permission deleted successfully.'
        );
    }
}
