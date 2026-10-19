<?php

namespace Src\Identity\User\UseCases;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cierra la sesión actual: revoca el token si la petición venía con bearer
 * (apps/PDA) y, si hay sesión, la invalida (web).
 */
class LogoutUseCase
{
    public function execute(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        // La sesión de la SPA usa un `TransientToken`: no hay fila que borrar.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
