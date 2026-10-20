<?php

namespace Src\Logistics\Location\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Distrito del padrón INEI (`ubigeo` de 6 dígitos).
 *
 * Es la unidad mínima de cobertura: la agencia declara qué ubicaciones atiende
 * (`agency_coverages`) y cada ubicación pertenece a una zona, que es lo que
 * consulta el motor de tarifas.
 *
 * El ubigeo se guarda **sin normalizar el nombre**: `department`/`province`/
 * `district` vienen del padrón en mayúsculas y se pueden editar para mostrarlos
 * como se quiera; el `ubigeo` es la clave.
 */
class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'ubigeo',
        'department',
        'province',
        'district',
        'zone_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return BelongsTo<Zone, Location> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Búsqueda por ubigeo, distrito, provincia o departamento.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';

        return $query->where(function (Builder $query) use ($needle): void {
            $query->where('ubigeo', 'like', $needle)
                ->orWhere('district', 'like', $needle)
                ->orWhere('province', 'like', $needle)
                ->orWhere('department', 'like', $needle);
        });
    }
}
