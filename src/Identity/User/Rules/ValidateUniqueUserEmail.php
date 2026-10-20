<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * El email no está registrado (`USER_EMAIL_TAKEN`). Se compara normalizado a
 * minúsculas (invariante 1 de `02-entidades.md`).
 *
 * En una edición el `UseCase` inyecta `user_id` para no chocar con el propio
 * registro.
 */
class ValidateUniqueUserEmail
{
    public function handle(array $payload, Closure $next): mixed
    {
        // Una edición que no toca el email no tiene nada que comprobar.
        if (! isset($payload['email'])) {
            return $next($payload);
        }

        $query = User::query()->where('email', strtolower((string) $payload['email']));

        if (isset($payload['user_id'])) {
            $query->whereKeyNot($payload['user_id']);
        }

        if ($query->exists()) {
            throw new BusinessRuleException(
                message: 'The email is already registered.',
                errorCode: 'USER_EMAIL_TAKEN',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
