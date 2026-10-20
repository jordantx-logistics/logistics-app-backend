<?php

namespace Src\Logistics\Tariff\Rules;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * No puede haber dos tarifarios **activos** del mismo `service_type` y alcance
 * con vigencias solapadas (`TARIFF_OVERLAP`, 409).
 *
 * «Mismo alcance» compara el par completo: dos `zone_pair` de zonas distintas
 * conviven; el mismo par, no. `valid_to = null` se trata como infinito, así que
 * un tarifario sin caducidad choca con cualquier otro del mismo par.
 */
class ValidateTariffOverlap
{
    public function handle(array $payload, Closure $next): mixed
    {
        $from = (string) $payload['valid_from'];
        $to = $payload['valid_to'] ?? null;

        $query = Tariff::query()
            ->where('service_type', $payload['service_type'])
            ->where('scope_type', $payload['scope_type'])
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $to ?? '9999-12-31')
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $from))
            ->when($payload['tariff_id'] ?? null, fn (Builder $query, int $id) => $query->whereKeyNot($id));

        match ($payload['scope_type']) {
            Tariff::SCOPE_ZONE_PAIR => $query
                ->where('origin_zone_id', $payload['origin_zone_id'])
                ->where('destination_zone_id', $payload['destination_zone_id']),
            Tariff::SCOPE_DISTRICT_PAIR => $query
                ->where('origin_location_id', $payload['origin_location_id'])
                ->where('destination_location_id', $payload['destination_location_id']),
            default => null,
        };

        $overlapping = $query->value('code');

        if ($overlapping !== null) {
            throw new BusinessRuleException(
                message: "The tariff overlaps with the active tariff [{$overlapping}] for the same scope and validity.",
                errorCode: 'TARIFF_OVERLAP',
                statusCode: 409,
            );
        }

        return $next($payload);
    }
}
