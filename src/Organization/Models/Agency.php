<?php

namespace Src\Organization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

class Agency extends Model
{
    use CanBeScopedByAgency, HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'parent_agency_id',
        'address',
        'phone',
        'district',
        'province',
        'department',
        'timezone',
        'is_active',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opened_at' => 'date',
        ];
    }

    /**
     * `agencies` se acota por su propio id, no por `agency_id`.
     *
     * El filtro (y el bypass de los roles nacionales) los pone el trait
     * `CanBeScopedByAgency`. La expansión por jerarquía (`parent_agency_id`)
     * llega con los endpoints de Identity.
     */
    public function agencyScopeColumn(): string
    {
        return 'id';
    }
}
