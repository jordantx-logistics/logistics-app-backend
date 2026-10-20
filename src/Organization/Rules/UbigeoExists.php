<?php

namespace Src\Organization\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Src\Shared\Contracts\LocationDirectory;

/**
 * El ubigeo de una agencia debe existir en el padrón (`locations`).
 *
 * `agencies` es de `Organization` y el padrón de `Logistics/Location`: se
 * consulta por el puerto `LocationDirectory` (ADR-0006). Un ubigeo inválido no
 * se guarda, porque el motor de tarifas lo usaría para resolver la zona y
 * acabaría en el tarifario nacional sin que nadie se enterase.
 */
class UbigeoExists implements ValidationRule
{
    public function __construct(
        private readonly LocationDirectory $locations,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->locations->locationIdForUbigeo($value) === null) {
            $fail('The selected ubigeo does not exist in the locations catalog.');
        }
    }
}
