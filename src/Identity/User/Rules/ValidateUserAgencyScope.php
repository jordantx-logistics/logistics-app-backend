<?php

namespace Src\Identity\User\Rules;

use App\Models\User;
use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Un rol operativo exige al menos una agencia en el ámbito
 * (`USER_WITHOUT_AGENCY_SCOPE`, invariante 3 de `02-entidades.md`).
 *
 * Los roles nacionales (`admin`, `analyst`) tienen ámbito global y pueden
 * quedarse sin agencias.
 */
class ValidateUserAgencyScope
{
    public function handle(array $payload, Closure $next): mixed
    {
        /** @var User $user */
        $user = $payload['user'];
        $agencyIds = $payload['agency_ids'] ?? [];

        if (! $user->hasNationalScope() && count($agencyIds) === 0) {
            throw new BusinessRuleException(
                message: 'A user with an operative role needs at least one agency in scope.',
                errorCode: 'USER_WITHOUT_AGENCY_SCOPE',
                statusCode: 422,
            );
        }

        return $next($payload);
    }
}
