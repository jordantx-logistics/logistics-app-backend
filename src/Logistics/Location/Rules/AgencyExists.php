<?php

namespace Src\Logistics\Location\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Src\Shared\Contracts\AgencyDirectory;

/**
 * `agency_id` de `agency_coverages` debe existir en `Organization`.
 *
 * Es una regla de validación (no de pipeline) porque `agency_coverages` es CRUD
 * sin más lógica: el único dato que no es del contexto es la agencia, y se
 * consulta por el puerto `AgencyDirectory` (ADR-0006).
 */
class AgencyExists implements ValidationRule
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! $this->agencies->agencyExists((int) $value)) {
            $fail('The selected agency does not exist.');
        }
    }
}
