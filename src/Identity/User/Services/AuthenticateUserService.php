<?php

namespace Src\Identity\User\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Src\Identity\User\Rules\ValidateUserActive;
use Src\Identity\User\Rules\ValidateUserNotLocked;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Verificación de credenciales compartida por el login web (UC-02) y la emisión
 * de token (UC-03).
 *
 * El login web y el token hacían la misma comprobación por separado; aquí vive
 * una sola vez, con las reglas que el spec exige: la cuenta no puede estar
 * bloqueada (`USER_LOCKED`) ni suspendida (`USER_INACTIVE`), se cuentan los
 * intentos fallidos y, al superar el tope, la cuenta se bloquea temporalmente.
 *
 * El mensaje de credenciales incorrectas es neutro (no revela si el email
 * existe); el estado de la cuenta solo se revela una vez acertada la contraseña.
 */
class AuthenticateUserService
{
    public function authenticate(array $credentials): User
    {
        $email = strtolower(trim((string) $credentials['email']));

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            if ($user !== null) {
                $this->registerFailedAttempt($user);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RulesPipeline::run(['user' => $user], [
            ValidateUserNotLocked::class,
            ValidateUserActive::class,
        ]);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * Suma un intento fallido; al alcanzar el tope, fija `locked_until`.
     */
    private function registerFailedAttempt(User $user): void
    {
        $attempts = (int) $user->failed_login_attempts + 1;

        $attributes = ['failed_login_attempts' => $attempts];

        if ($attempts >= (int) config('identity.max_login_attempts')) {
            $attributes['locked_until'] = now()->addMinutes((int) config('identity.lock_minutes'));
        }

        $user->forceFill($attributes)->save();
    }
}
