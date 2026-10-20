<?php

namespace Src\Logistics\Tariff\Rules;

use Closure;
use Illuminate\Support\Carbon;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Invariantes de definición de un tarifario (`spec/04-modulos/core-logistica/02-entidades.md`).
 *
 * - El `scope_type` exige (y prohíbe) el par de zonas o de ubicaciones: un
 *   tarifario `national` no lleva ids y uno `zone_pair`/`district_pair` exige
 *   el par completo.
 * - `valid_from <= valid_to` (si `valid_to` viene; nulo = sin caducidad).
 * - Los tramos van en orden, no se solapan (`from_kg` > `to_kg` anterior) y
 *   cada uno empieza antes del límite del tarifario (`max_weight_kg`).
 *
 * Devuelve códigos distintos por invariante para que el cliente sepa qué
 * corregir: `TARIFF_SCOPE_INVALID`, `TARIFF_VALIDITY_INVALID`,
 * `TARIFF_BRACKETS_INVALID`.
 */
class ValidateTariffDefinition
{
    public function handle(array $payload, Closure $next): mixed
    {
        $this->validateScope($payload);
        $this->validateValidity($payload);
        $this->validateBrackets($payload);

        return $next($payload);
    }

    private function validateScope(array $payload): void
    {
        $scope = $payload['scope_type'];
        $zones = [$payload['origin_zone_id'] ?? null, $payload['destination_zone_id'] ?? null];
        $locations = [$payload['origin_location_id'] ?? null, $payload['destination_location_id'] ?? null];

        $valid = match ($scope) {
            Tariff::SCOPE_NATIONAL => $zones === [null, null] && $locations === [null, null],
            Tariff::SCOPE_ZONE_PAIR => ! in_array(null, $zones, true) && $locations === [null, null],
            Tariff::SCOPE_DISTRICT_PAIR => ! in_array(null, $locations, true) && $zones === [null, null],
            default => false,
        };

        if (! $valid) {
            throw new BusinessRuleException(
                message: "The tariff scope [{$scope}] does not match the origin/destination pair.",
                errorCode: 'TARIFF_SCOPE_INVALID',
            );
        }
    }

    private function validateValidity(array $payload): void
    {
        $from = Carbon::parse($payload['valid_from']);

        if (($payload['valid_to'] ?? null) !== null && Carbon::parse($payload['valid_to'])->lt($from)) {
            throw new BusinessRuleException(
                message: 'The tariff validity is inverted: valid_to is before valid_from.',
                errorCode: 'TARIFF_VALIDITY_INVALID',
            );
        }
    }

    private function validateBrackets(array $payload): void
    {
        $brackets = $payload['brackets'] ?? [];

        if ($brackets === []) {
            return;
        }

        usort($brackets, fn (array $a, array $b): int => (float) $a['from_kg'] <=> (float) $b['from_kg']);

        $previousTo = null;
        $maxWeight = (float) $payload['max_weight_kg'];

        foreach ($brackets as $index => $bracket) {
            $from = (float) $bracket['from_kg'];
            $to = (float) $bracket['to_kg'];

            $invalid = $to <= $from
                || $from < 0
                || ($previousTo !== null && $from < $previousTo)
                || $from > $maxWeight;

            if ($invalid) {
                throw new BusinessRuleException(
                    message: "The tariff bracket #{$index} is invalid: it must be ordered, non-overlapping and within max_weight_kg.",
                    errorCode: 'TARIFF_BRACKETS_INVALID',
                );
            }

            $previousTo = $to;
        }
    }
}
