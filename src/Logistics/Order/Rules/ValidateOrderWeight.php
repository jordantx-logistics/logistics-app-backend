<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Shared\Exceptions\BusinessRuleException;

class ValidateOrderWeight
{
    private const MAX_WEIGHT_KG = 500;

    public function handle(array $payload, Closure $next): mixed
    {
        if (($payload['weight_kg'] ?? 0) > self::MAX_WEIGHT_KG) {
            throw new BusinessRuleException(
                message: 'Order weight exceeds the maximum of '.self::MAX_WEIGHT_KG.' kg.',
                errorCode: 'WEIGHT_EXCEEDED',
            );
        }

        return $next($payload);
    }
}
