<?php

namespace Src\Auth\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Auth\Requests\LoginRequest;
use Src\Auth\Requests\RegisterRequest;
use Src\Auth\Resources\AuthResource;
use Src\Auth\UseCases\LoginUseCase;
use Src\Auth\UseCases\RegisterUseCase;

/**
 * @group Authentication
 *
 * APIs for managing users and tokens.
 */
class AuthController extends Controller
{
    /**
     * User Registration
     *
     * Creates a new user in the system and automatically provisions them with a JWT access token.
     *
     * @response 201 {
     *   "success": true,
     *   "message": "User registered successfully.",
     *   "data": {
     *     "user": {"id": 1, "name": "Test User", "email": "test@test.com", "roles": ["Customer"]},
     *     "token": "eyJ0...",
     *     "expires_in": 3600
     *   }
     * }
     */
    public function register(RegisterRequest $request, RegisterUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->validated());

        return $this->success(
            data: AuthResource::make($result),
            message: 'User registered successfully.',
            statusCode: 201
        );
    }

    /**
     * Retrieve Access Token
     *
     * Authenticates a user using email and password, returning a JWT token for use in subsequent authenticated requests.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Successfully authenticated.",
     *   "data": {
     *     "user": {"id": 1, "name": "Admin", "email": "admin@test.com", "roles": ["SuperAdmin"]},
     *     "token": "eyJ0...",
     *     "expires_in": 3600
     *   }
     * }
     */
    public function login(LoginRequest $request, LoginUseCase $useCase): JsonResponse
    {
        $result = $useCase->execute($request->validated());

        return $this->success(
            data: AuthResource::make($result),
            message: 'Successfully authenticated.'
        );
    }

    /**
     * Terminate Session
     *
     * Invalidates the current JWT token, ensuring it can no longer be used for authenticated requests.
     *
     * @response 200 {"success": true, "message": "Successfully logged out."}
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return $this->success(message: 'Successfully logged out.');
    }

    /**
     * Refresh Access Token
     *
     * Issues a new JWT token assuming the current one is still within its refresh window.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Token refreshed successfully.",
     *   "data": {
     *     "token": "eyJ0eXAi...",
     *     "expires_in": 3600
     *   }
     * }
     */
    public function refresh(): JsonResponse
    {
        return $this->success(
            data: [
                'token' => auth('api')->refresh(),
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ],
            message: 'Token refreshed successfully.'
        );
    }
}
