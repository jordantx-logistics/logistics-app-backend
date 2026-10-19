<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Token opaco para apps/PDA. Verifica las credenciales contra el provider sin
 * abrir sesión (no queremos cookie para un cliente nativo) y guarda el token
 * hasheado en `personal_access_tokens`: el valor en claro solo se devuelve una vez.
 */
class IssueTokenUseCase
{
    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, expires_in: int|null}
     */
    public function execute(array $credentials, string $deviceName, ?string $platform = null): array
    {
        $guard = Auth::guard('web');

        if (! $guard->validate($credentials)) {
            // Mismo mensaje que el login: no revela si el email existe.
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted() ?? User::where('email', $credentials['email'])->firstOrFail();

        $name = $platform === null || $platform === ''
            ? $deviceName
            : sprintf('%s (%s)', $deviceName, $platform);

        $expiration = config('sanctum.expiration');

        return [
            'token' => $user->createToken($name)->plainTextToken,
            'expires_in' => $expiration === null ? null : ((int) $expiration) * 60,
        ];
    }
}
