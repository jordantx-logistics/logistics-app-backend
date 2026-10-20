<?php

namespace Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué ubicaciones atiende una agencia destino y con qué modalidades.
 *
 * `agency_id` es de `Organization` (sin FK, ADR-0006); se valida con
 * `AgencyDirectory` al crear o editar. `allows_delivery` habilita el reparto a
 * domicilio en esa ubicación y `delivery_surcharge` es el recargo que se suma a
 * la tarifa cuando la orden se entrega a domicilio.
 *
 * La cobertura **no** decide el precio base: eso es del tarifario. Decide si la
 * agencia puede entregar ahí y cuánto se recarga.
 */
class AgencyCoverage extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'location_id',
        'allows_pickup',
        'allows_delivery',
        'delivery_surcharge',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_pickup' => 'boolean',
            'allows_delivery' => 'boolean',
            'delivery_surcharge' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'allows_pickup' => true,
        'allows_delivery' => false,
        'delivery_surcharge' => 0,
        'is_active' => true,
    ];

    /** @return BelongsTo<Location, AgencyCoverage> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
