<?php

namespace Src\Customer\Rules;

use Closure;
use Src\Customer\Models\Customer;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Formato del documento del cliente (`CUSTOMER_DOCUMENT_INVALID`, 422).
 *
 * - **DNI**: 8 dígitos.
 * - **RUC**: 11 dígitos, prefijo válido (10/15/17/20) y dígito verificador
 *   módulo 11 (SUNAT).
 * - **CE**: 9–12 alfanuméricos.
 * - **PASSPORT**: 6–12 alfanuméricos.
 *
 * Normaliza tipo y número en el payload (`mayúsculas`, sin espacios) para que
 * la unicidad y la búsqueda no dependan de cómo lo escribió el usuario.
 */
class ValidateCustomerDocument
{
    private const RUC_PREFIXES = ['10', '15', '17', '20'];

    /** Pesos del dígito verificador del RUC (SUNAT). */
    private const RUC_WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function handle(array $payload, Closure $next): mixed
    {
        $type = strtoupper(trim((string) ($payload['document_type'] ?? '')));
        $number = strtoupper(preg_replace('/\s+/', '', (string) ($payload['document_number'] ?? '')) ?? '');

        if (! in_array($type, Customer::DOCUMENT_TYPES, true)) {
            throw new BusinessRuleException(
                message: "Unsupported document type [{$type}]. Allowed: ".implode(', ', Customer::DOCUMENT_TYPES).'.',
                errorCode: 'CUSTOMER_DOCUMENT_INVALID',
            );
        }

        $valid = match ($type) {
            'DNI' => preg_match('/^\d{8}$/', $number) === 1,
            'RUC' => $this->isValidRuc($number),
            'CE' => preg_match('/^[A-Z0-9]{9,12}$/', $number) === 1,
            'PASSPORT' => preg_match('/^[A-Z0-9]{6,12}$/', $number) === 1,
        };

        if (! $valid) {
            throw new BusinessRuleException(
                message: "The {$type} document [{$number}] is not valid.",
                errorCode: 'CUSTOMER_DOCUMENT_INVALID',
            );
        }

        $payload['document_type'] = $type;
        $payload['document_number'] = $number;

        return $next($payload);
    }

    /** RUC peruano: 11 dígitos con dígito verificador módulo 11. */
    private function isValidRuc(string $ruc): bool
    {
        if (preg_match('/^\d{11}$/', $ruc) !== 1) {
            return false;
        }

        if (! in_array(substr($ruc, 0, 2), self::RUC_PREFIXES, true)) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 10; $position++) {
            $sum += (int) $ruc[$position] * self::RUC_WEIGHTS[$position];
        }

        $remainder = 11 - ($sum % 11);
        $checkDigit = match (true) {
            $remainder === 10 => 0,
            $remainder === 11 => 1,
            default => $remainder,
        };

        return $checkDigit === (int) $ruc[10];
    }
}
