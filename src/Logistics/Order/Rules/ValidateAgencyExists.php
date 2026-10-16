<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Organization\Models\Agency;
use Src\Shared\Exceptions\BusinessRuleException;

class ValidateAgencyExists
{
    public function handle(array $payload, Closure $next): mixed
    {
        $originExists = Agency::where('id', $payload['origin_agency_id'])->exists();
        if (! $originExists) {
            throw new BusinessRuleException('Origin Agency not found.', 'AGENCY_NOT_FOUND');
        }

        $destinationExists = Agency::where('id', $payload['destination_agency_id'])->exists();
        if (! $destinationExists) {
            throw new BusinessRuleException('Destination Agency not found.', 'AGENCY_NOT_FOUND');
        }

        return $next($payload);
    }
}
