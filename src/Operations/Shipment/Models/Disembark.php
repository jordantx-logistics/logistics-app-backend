<?php

namespace Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disembark extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'embark_id',
        'arrived_at',
    ];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Embark, $this> */
    public function embark(): BelongsTo
    {
        return $this->belongsTo(Embark::class);
    }

    /** @return HasMany<DisembarkItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DisembarkItem::class)->orderBy('id');
    }
}
