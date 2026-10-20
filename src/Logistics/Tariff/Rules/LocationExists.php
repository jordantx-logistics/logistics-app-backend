<?php

namespace Src\Logistics\Tariff\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Src\Shared\Contracts\LocationDirectory;

/**
 * `origin_location_id`/`destination_location_id` deben existir en
 * `Logistics/Location`, comprobado por el puerto (ADR-0006).
 */
class LocationExists implements ValidationRule
{
    public function __construct(
        private readonly LocationDirectory $locations,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! $this->locations->locationExists((int) $value)) {
            $fail('The selected location does not exist.');
        }
    }
}
