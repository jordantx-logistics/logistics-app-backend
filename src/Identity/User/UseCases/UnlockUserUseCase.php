<?php

namespace Src\Identity\User\UseCases;

use App\Models\User;

/**
 * Desbloqueo manual de una cuenta (UC-07): limpia `locked_until` y
 * `failed_login_attempts`. Lo usa un admin cuando el bloqueo automático por
 * intentos fallidos deja fuera a un usuario legítimo.
 */
class UnlockUserUseCase
{
    public function execute(User $user): User
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        return $user;
    }
}
