<?php

namespace Src\Identity\User\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Identity\User\Requests\AssignAgencyScopeRequest;
use Src\Identity\User\Requests\StoreUserRequest;
use Src\Identity\User\Requests\UpdateUserRequest;
use Src\Identity\User\Requests\UpdateUserStatusRequest;
use Src\Identity\User\Resources\UserResource;
use Src\Identity\User\UseCases\AssignAgencyScopeUseCase;
use Src\Identity\User\UseCases\ListUsersUseCase;
use Src\Identity\User\UseCases\RegisterUserUseCase;
use Src\Identity\User\UseCases\UnlockUserUseCase;
use Src\Identity\User\UseCases\UpdateUserStatusUseCase;
use Src\Identity\User\UseCases\UpdateUserUseCase;

/**
 * @group Identity - Users
 *
 * APIs para administrar usuarios, su estado y su ámbito de agencia (UC-01, UC-06, UC-07, UC-16).
 */
class UserController extends Controller
{
    /**
     * List Users
     *
     * Lista los usuarios, filtrables por estado, texto (nombre/email) y agencia.
     *
     * @queryParam status string active,suspended,inactive. Example: active
     * @queryParam q string Busca por nombre o email. Example: ana
     * @queryParam agency_id integer Usuarios con esa agencia en su ámbito. Example: 42
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @apiResourceCollection Src\Identity\User\Resources\UserResource
     *
     * @apiResourceModel App\Models\User paginate=15
     *
     * @apiResourceAdditional success=true message="Users retrieved successfully."
     */
    public function index(Request $request, ListUsersUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListUsersUseCase::DEFAULT_PER_PAGE), 1),
            ListUsersUseCase::MAX_PER_PAGE,
        );

        $users = $useCase->execute(
            $request->only(['status', 'q', 'agency_id']),
            $perPage,
        );

        return $this->successPaginated(
            resource: UserResource::collection($users),
            message: 'Users retrieved successfully.',
        );
    }

    /**
     * Create User
     *
     * Da de alta un usuario (UC-01). Sin roles, se asigna `employee`.
     *
     * @apiResource Src\Identity\User\Resources\UserResource status=201
     *
     * @apiResourceModel App\Models\User
     *
     * @apiResourceAdditional success=true message="User created successfully."
     */
    public function store(StoreUserRequest $request, RegisterUserUseCase $useCase): JsonResponse
    {
        $user = $useCase->execute($request->validated());

        return $this->success(
            data: UserResource::make($user),
            message: 'User created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Request User Details
     *
     * @apiResource Src\Identity\User\Resources\UserResource
     *
     * @apiResourceModel App\Models\User
     *
     * @apiResourceAdditional success=true message="User retrieved successfully."
     */
    public function show(User $user): JsonResponse
    {
        $user->load('agencies');

        return $this->success(
            data: UserResource::make($user),
            message: 'User retrieved successfully.',
        );
    }

    /**
     * Modify User
     *
     * Actualiza nombre, email o el vínculo con RRHH del usuario.
     *
     * @apiResource Src\Identity\User\Resources\UserResource
     *
     * @apiResourceModel App\Models\User
     *
     * @apiResourceAdditional success=true message="User updated successfully."
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserUseCase $useCase): JsonResponse
    {
        $user = $useCase->execute($user, $request->validated());

        $user->load('agencies');

        return $this->success(
            data: UserResource::make($user),
            message: 'User updated successfully.',
        );
    }

    /**
     * Change User Status
     *
     * Suspende, reactiva o da de baja al usuario (UC-06). Al desactivarlo se revocan sus tokens.
     *
     * @apiResource Src\Identity\User\Resources\UserResource
     *
     * @apiResourceModel App\Models\User
     *
     * @apiResourceAdditional success=true message="User status updated successfully."
     */
    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user,
        UpdateUserStatusUseCase $useCase,
    ): JsonResponse {
        $user = $useCase->execute($user, $request->validated('status'));

        $user->load('agencies');

        return $this->success(
            data: UserResource::make($user),
            message: 'User status updated successfully.',
        );
    }

    /**
     * Suspend User
     *
     * Atajo de UC-06: suspende al usuario sin pasar por el cambio de estado genérico.
     *
     * @response 200 {"success": true, "message": "User suspended successfully."}
     */
    public function destroy(User $user, UpdateUserStatusUseCase $useCase): JsonResponse
    {
        $useCase->execute($user, User::STATUS_SUSPENDED);

        return $this->success(message: 'User suspended successfully.');
    }

    /**
     * Unlock User
     *
     * Limpia el bloqueo por intentos fallidos (UC-07).
     *
     * @response 200 {"success": true, "message": "User unlocked successfully."}
     */
    public function unlock(User $user, UnlockUserUseCase $useCase): JsonResponse
    {
        $useCase->execute($user);

        return $this->success(message: 'User unlocked successfully.');
    }

    /**
     * Assign Agency Scope
     *
     * Sincroniza el ámbito de agencia del usuario y su agencia principal (UC-16).
     *
     * @apiResource Src\Identity\User\Resources\UserResource
     *
     * @apiResourceModel App\Models\User
     *
     * @apiResourceAdditional success=true message="Agency scope updated successfully."
     */
    public function assignAgencyScope(
        AssignAgencyScopeRequest $request,
        User $user,
        AssignAgencyScopeUseCase $useCase,
    ): JsonResponse {
        $user = $useCase->execute(
            $user,
            array_map('intval', $request->validated('agency_ids')),
            $request->validated('primary_agency_id'),
        );

        return $this->success(
            data: UserResource::make($user),
            message: 'Agency scope updated successfully.',
        );
    }
}
