<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * La cuenta no está bloqueada por intentos fallidos (`USER_LOCKED`, HTTP 423).
 *
 * `locked_until` en el futuro significa bloqueo temporal; un admin lo levanta
 * antes de tiempo con `POST /users/{id}/unlock` (UC-07).
 */
class ValidateUserNotLocked
{
    public function handle(array $payload, Closure $next): mixed
    {
        $user = $payload['user'] ?? null;

        if ($user instanceof User && $user->locked_until?->isFuture()) {
            throw new BusinessRuleException(
                message: 'The user account is temporarily locked.',
                errorCode: 'USER_LOCKED',
                statusCode: 423,
            );
        }

        return $next($payload);
    }
}
