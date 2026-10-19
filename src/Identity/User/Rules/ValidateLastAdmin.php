<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * No se puede dejar el sistema sin `admin` activo (`LAST_ADMIN_PROTECTED`,
 * invariante de la máquina de estados de `07-reglas.md`).
 *
 * Corre cuando el payload desactiva al usuario (`status` distinto de `active`);
 * si el objetivo tiene el rol `admin` y no queda ningún otro admin activo, se
 * bloquea.
 */
class ValidateLastAdmin
{
    public function handle(array $payload, Closure $next): mixed
    {
        $user = $payload['user'];
        $status = $payload['status'] ?? null;

        $deactivating = $status !== null && $status !== User::STATUS_ACTIVE;

        if ($deactivating && $user->hasRole('admin')) {
            $otherActiveAdmins = User::query()
                ->role('admin')
                ->where('status', User::STATUS_ACTIVE)
                ->whereKeyNot($user->id)
                ->count();

            if ($otherActiveAdmins === 0) {
                throw new BusinessRuleException(
                    message: 'The last active administrator cannot be deactivated.',
                    errorCode: 'LAST_ADMIN_PROTECTED',
                    statusCode: 422,
                );
            }
        }

        return $next($payload);
    }
}
