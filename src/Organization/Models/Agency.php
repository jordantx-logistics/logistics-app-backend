<?php

namespace Src\Organization\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'district',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Acota la consulta a las agencias del usuario (`$user->agencyIds()`).
     *
     * Un array vacío no ve nada: un usuario operativo sin ámbito es un error de
     * configuración, no un nacional. El bypass de los roles nacionales
     * (`admin`, `analyst`) lo decide el controlador con `hasNationalScope()`.
     * La expansión por jerarquía (`parent_agency_id`) llega con Identity (Fase 2).
     *
     * @param  Builder<Agency>  $query
     * @param  list<int>  $ids
     * @return Builder<Agency>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->whereIn('id', $ids);
    }
}
