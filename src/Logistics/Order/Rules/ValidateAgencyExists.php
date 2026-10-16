<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Exceptions\BusinessRuleException;

class ValidateAgencyExists
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        if (! $this->agencies->agencyExists((int) $payload['origin_agency_id'])) {
            throw new BusinessRuleException('Origin Agency not found.', 'AGENCY_NOT_FOUND');
        }

        if (! $this->agencies->agencyExists((int) $payload['destination_agency_id'])) {
            throw new BusinessRuleException('Destination Agency not found.', 'AGENCY_NOT_FOUND');
        }

        return $next($payload);
    }
}
