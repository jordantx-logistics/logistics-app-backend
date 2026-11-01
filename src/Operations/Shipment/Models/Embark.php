<?php

namespace Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Traits\CanBeScopedByAgency;

class Embark extends Model
{
    use CanBeScopedByAgency, HasFactory;

    protected $fillable = [
        'agency_id',
        'vehicle_id',
        'driver_id',
        'route_ref',
        'departed_at',
    ];

    protected function casts(): array
    {
        return [
            'departed_at' => 'datetime',
        ];
    }

    /** @return HasMany<EmbarkItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EmbarkItem::class)->orderBy('id');
    }

    /** @return HasMany<Disembark, $this> */
    public function disembarks(): HasMany
    {
        return $this->hasMany(Disembark::class);
    }
}
