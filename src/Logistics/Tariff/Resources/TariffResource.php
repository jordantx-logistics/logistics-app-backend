<?php

namespace Src\Logistics\Tariff\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Logistics\Tariff\Models\Tariff;

/**
 * @mixin Tariff
 */
class TariffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'service_type' => $this->service_type,
            'scope_type' => $this->scope_type,
            'origin_zone_id' => $this->origin_zone_id,
            'destination_zone_id' => $this->destination_zone_id,
            'origin_location_id' => $this->origin_location_id,
            'destination_location_id' => $this->destination_location_id,
            'base_price' => $this->base_price,
            'price_per_kg' => $this->price_per_kg,
            'price_per_m3' => $this->price_per_m3,
            'min_charge' => $this->min_charge,
            'max_weight_kg' => $this->max_weight_kg,
            'volumetric_factor' => $this->volumetric_factor,
            'priority' => $this->priority,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'is_active' => $this->is_active,
            'brackets' => $this->whenLoaded('brackets', fn () => $this->brackets->map(fn ($bracket): array => [
                'id' => $bracket->id,
                'from_kg' => $bracket->from_kg,
                'to_kg' => $bracket->to_kg,
                'fixed_fee' => $bracket->fixed_fee,
                'price_per_kg' => $bracket->price_per_kg,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
