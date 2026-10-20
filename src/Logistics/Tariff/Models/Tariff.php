<?php

namespace Src\Logistics\Tariff\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarifario (raíz de agregado `Tariff`).
 *
 * Resuelve el precio de una orden por `service_type` y alcance:
 *
 * - `national`: aplica a cualquier ruta (el más genérico).
 * - `zone_pair`: exige `origin_zone_id` + `destination_zone_id`.
 * - `district_pair`: exige `origin_location_id` + `destination_location_id`.
 *
 * Ante varios candidatos gana el alcance más específico, luego `priority` y
 * luego el más reciente (`EloquentTariffDirectory`). La vigencia es por fecha
 * (`valid_from`/`valid_to`, este último nullable = sin caducidad).
 */
class Tariff extends Model
{
    use HasFactory;

    public const SERVICE_STANDARD = 'standard';

    public const SERVICE_EXPRESS = 'express';

    public const SERVICE_TYPES = [self::SERVICE_STANDARD, self::SERVICE_EXPRESS];

    public const SCOPE_NATIONAL = 'national';

    public const SCOPE_ZONE_PAIR = 'zone_pair';

    public const SCOPE_DISTRICT_PAIR = 'district_pair';

    public const SCOPE_TYPES = [self::SCOPE_NATIONAL, self::SCOPE_ZONE_PAIR, self::SCOPE_DISTRICT_PAIR];

    protected $fillable = [
        'code',
        'name',
        'service_type',
        'scope_type',
        'origin_zone_id',
        'destination_zone_id',
        'origin_location_id',
        'destination_location_id',
        'base_price',
        'price_per_kg',
        'price_per_m3',
        'min_charge',
        'max_weight_kg',
        'volumetric_factor',
        'priority',
        'valid_from',
        'valid_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'price_per_kg' => 'decimal:2',
            'price_per_m3' => 'decimal:2',
            'min_charge' => 'decimal:2',
            'max_weight_kg' => 'decimal:3',
            'volumetric_factor' => 'integer',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = ['is_active' => true];

    /** @return HasMany<TariffBracket> */
    public function brackets(): HasMany
    {
        return $this->hasMany(TariffBracket::class)->orderBy('from_kg');
    }

    /**
     * @param  Builder<Tariff>  $query
     * @return Builder<Tariff>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Vigente hoy: `valid_from <= hoy` y (`valid_to` nulo o `>= hoy`).
     *
     * @param  Builder<Tariff>  $query
     * @return Builder<Tariff>
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', now()->toDateString()));
    }
}
