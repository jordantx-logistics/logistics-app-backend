<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Un usuario `suspended`/`inactive` no opera (`USER_INACTIVE`, invariante 2 de
 * `02-entidades.md`).
 *
 * La regla recibe el `User` en el payload (`$payload['user']`) para poder correr
 * tanto en el login como en cualquier caso de uso que opere en nombre de otro.
 */
class ValidateUserActive
{
    public function handle(array $payload, Closure $next): mixed
    {
        $user = $payload['user'] ?? null;

        if ($user instanceof User && $user->status !== User::STATUS_ACTIVE) {
            throw new BusinessRuleException(
                message: 'The user account is not active.',
                errorCode: 'USER_INACTIVE',
                statusCode: 403,
            );
        }

        return $next($payload);
    }
}
