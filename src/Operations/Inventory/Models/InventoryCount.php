<?php

namespace Src\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Traits\CanBeScopedByAgency;

class InventoryCount extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_FINISHED = 'finished';

    protected $fillable = [
        'agency_id',
        'status',
        'counted_by',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<InventoryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class)->orderBy('id');
    }
}
