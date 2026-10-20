<?php

namespace Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona de tarificación: agrupa ubicaciones (distritos) para resolver precios por
 * origen/destino.
 *
 * El seed inicial crea **una zona por departamento** (`ZON-01`…`ZON-25`), pero
 * las filas son datos de negocio y se pueden renombrar o reagrupar desde la API
 * (`locations.manage`). El código es la clave estable: la tarifa apunta a la zona,
 * no al nombre.
 */
class Zone extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active'];

    /** Coincide con el default de la migración (así el modelo recién creado lo trae). */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Location> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * @param  Builder<Zone>  $query
     * @return Builder<Zone>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
