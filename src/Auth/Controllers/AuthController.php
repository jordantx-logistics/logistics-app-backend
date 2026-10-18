<?php

namespace Src\Auth\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Auth\Requests\ForgotPasswordRequest;
use Src\Auth\Requests\LoginRequest;
use Src\Auth\Requests\ResetPasswordRequest;
use Src\Auth\Requests\TokenRequest;
use Src\Auth\Resources\MeResource;
use Src\Auth\Resources\TokenResource;
use Src\Auth\UseCases\IssueTokenUseCase;
use Src\Auth\UseCases\LoginUseCase;
use Src\Auth\UseCases\LogoutAllUseCase;
use Src\Auth\UseCases\LogoutUseCase;
use Src\Auth\UseCases\ResetPasswordUseCase;
use Src\Auth\UseCases\SendPasswordResetLinkUseCase;

/**
 * @group Authentication
 *
 * APIs for managing sessions and access tokens (Sanctum).
 */
class AuthController extends Controller
{
    /**
     * Login (web)
     *
     * Authenticates against the session guard and sets the `HttpOnly` session
     * cookie. Requires the CSRF cookie (`GET /sanctum/csrf-cookie`) first.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Successfully authenticated.",
     *   "data": {
     *     "user": {"id": 1, "name": "Admin", "email": "admin@test.com"},
     *     "roles": ["admin"],
     *     "permissions": ["orders.create"],
     *     "agencies": [1]
     *   }
     * }
     */
    public function login(LoginRequest $request, LoginUseCase $useCase): JsonResponse
    {
        $user = $useCase->execute($request->validated());

        // Sesión nueva tras autenticar: evita la fijación de sesión. Solo hay
        // sesión cuando la petición viene del frontend (dominio stateful).
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->success(
            data: MeResource::make($user),
            message: 'Successfully authenticated.'
        );
    }

    /**
     * Issue Token (apps / PDA)
     *
     * Returns an opaque token to send as `Authorization: Bearer <token>`.
     * The plain value is shown only once.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Token issued successfully.",
     *   "data": {"token": "1|plainTextToken", "token_type": "Bearer", "expires_in": 2592000}
     * }
     */
    public function token(TokenRequest $request, IssueTokenUseCase $useCase): JsonResponse
    {
        $data = $request->validated();

        $result = $useCase->execute(
            credentials: [
                'email' => $data['email'],
                'password' => $data['password'],
            ],
            deviceName: $data['device_name'],
            platform: $data['platform'] ?? null,
        );

        return $this->success(
            data: TokenResource::make($result),
            message: 'Token issued successfully.'
        );
    }

    /**
     * Current User
     *
     * Returns the authenticated user with roles, permissions and agency scope.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User retrieved successfully.",
     *   "data": {
     *     "user": {"id": 1, "name": "Admin", "email": "admin@test.com"},
     *     "roles": ["admin"],
     *     "permissions": ["orders.create"],
     *     "agencies": [1]
     *   }
     * }
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(
            data: MeResource::make($request->user()),
            message: 'User retrieved successfully.'
        );
    }

    /**
     * Logout
     *
     * Revokes the current access token (apps) and closes the session (web).
     *
     * @response 200 {"success": true, "message": "Successfully logged out."}
     */
    public function logout(Request $request, LogoutUseCase $useCase): JsonResponse
    {
        $useCase->execute($request);

        return $this->success(message: 'Successfully logged out.');
    }

    /**
     * Logout All Devices
     *
     * Revokes every token of the user. A later request with a revoked token
     * answers 401.
     *
     * @response 200 {"success": true, "message": "Successfully logged out from all devices."}
     */
    public function logoutAll(Request $request, LogoutAllUseCase $useCase): JsonResponse
    {
        $useCase->execute($request);

        return $this->success(message: 'Successfully logged out from all devices.');
    }

    /**
     * Forgot Password
     *
     * Sends the reset link when the email exists. The answer is neutral on
     * purpose: it never reveals whether the account exists.
     *
     * @response 200 {"success": true, "message": "If the email is registered, we have sent the reset link."}
     */
    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetLinkUseCase $useCase): JsonResponse
    {
        $useCase->execute($request->validated()['email']);

        return $this->success(
            message: 'If the email is registered, we have sent the reset link.'
        );
    }

    /**
     * Reset Password
     *
     * Consumes the token from the email and sets the new password. Revokes
     * every access token of the user.
     *
     * @response 200 {"success": true, "message": "Password reset successfully."}
     */
    public function resetPassword(ResetPasswordRequest $request, ResetPasswordUseCase $useCase): JsonResponse
    {
        $useCase->execute($request->validated());

        return $this->success(message: 'Password reset successfully.');
    }
}
