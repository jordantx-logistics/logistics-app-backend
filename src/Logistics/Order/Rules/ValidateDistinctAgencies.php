<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Origen y destino no pueden ser la misma agencia
 * (`SAME_ORIGIN_DESTINATION`, invariante 4 de la orden).
 */
class ValidateDistinctAgencies
{
    public function handle(array $payload, Closure $next): mixed
    {
        if ((int) $payload['origin_agency_id'] === (int) $payload['destination_agency_id']) {
            throw new BusinessRuleException(
                message: 'The origin and destination agencies must be different.',
                errorCode: 'SAME_ORIGIN_DESTINATION',
            );
        }

        return $next($payload);
    }
}
