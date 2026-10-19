<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login web: sesión de Laravel en el guard `web` (cookie HttpOnly + CSRF que
 * añade `EnsureFrontendRequestsAreStateful` para las peticiones de la SPA).
 *
 * Las apps/PDA no pasan por aquí: piden un token con `IssueTokenUseCase`.
 */
class LoginUseCase
{
    public function execute(array $credentials): User
    {
        if (! Auth::guard('web')->attempt($credentials)) {
            // Mensaje neutro: no revela si el email existe.
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return Auth::guard('web')->user();
    }
}
