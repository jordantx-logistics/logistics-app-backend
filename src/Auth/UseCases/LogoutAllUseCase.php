<?php

namespace Src\Auth\UseCases;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Revoca todos los tokens del usuario y cierra la sesión actual. Es la
 * revocación por dispositivo que pedía el ADR-0002: la petición siguiente
 * con el token viejo responde 401.
 */
class LogoutAllUseCase
{
    /**
     * @return int número de tokens revocados
     */
    public function execute(Request $request): int
    {
        $revoked = $request->user()->tokens()->delete();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $revoked;
    }
}
