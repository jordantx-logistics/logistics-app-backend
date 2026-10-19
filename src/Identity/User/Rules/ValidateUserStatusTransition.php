<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Transiciones válidas de la máquina de estados del usuario
 * (`spec/04-modulos/identity/07-reglas.md`): `active ↔ suspended`,
 * `active/suspended → inactive`, y `inactive` es final.
 *
 * El `error_code` no aparece en la tabla de reglas (la tabla no enumera las
 * transiciones); se documenta aquí para que el frontend pueda mapearlo.
 */
class ValidateUserStatusTransition
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        User::STATUS_ACTIVE => [User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_INACTIVE],
        User::STATUS_SUSPENDED => [User::STATUS_SUSPENDED, User::STATUS_ACTIVE, User::STATUS_INACTIVE],
        User::STATUS_INACTIVE => [User::STATUS_INACTIVE],
    ];

    public function handle(array $payload, Closure $next): mixed
    {
        /** @var User $user */
        $user = $payload['user'];
        $target = $payload['status'];

        $allowed = self::ALLOWED[$user->status] ?? [];

        if (! in_array($target, $allowed, true)) {
            throw new BusinessRuleException(
                message: "Cannot change the user status from {$user->status} to {$target}.",
                errorCode: 'INVALID_STATUS_TRANSITION',
                statusCode: 422,
            );
        }

        return $next($payload);
    }
}
