<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Src\Identity\User\Services\AuthenticateUserService;

/**
 * Login web: sesión de Laravel en el guard `web` (cookie HttpOnly + CSRF que
 * añade `EnsureFrontendRequestsAreStateful` para las peticiones de la SPA).
 *
 * Las apps/PDA no pasan por aquí: piden un token con `IssueTokenUseCase`.
 *
 * La verificación de credenciales (bloqueo, estado, intentos fallidos) vive en
 * `AuthenticateUserService`, compartida con la emisión de token.
 */
class LoginUseCase
{
    public function __construct(
        private readonly AuthenticateUserService $authenticator,
    ) {}

    public function execute(array $credentials): User
    {
        $user = $this->authenticator->authenticate($credentials);

        Auth::guard('web')->login($user);

        return $user;
    }
}
