<?php

namespace Src\Logistics\Tariff\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Src\Shared\Contracts\LocationDirectory;

/**
 * `origin_zone_id`/`destination_zone_id` deben existir en `Logistics/Location`.
 *
 * El tarifario referencia zonas de otro contexto: columna indexada sin FK
 * (ADR-0006) y la existencia se comprueba por el puerto `LocationDirectory`,
 * nunca con un `exists:zones,id` contra una tabla ajena.
 */
class ZoneExists implements ValidationRule
{
    public function __construct(
        private readonly LocationDirectory $locations,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! $this->locations->zoneExists((int) $value)) {
            $fail('The selected zone does not exist.');
        }
    }
}
