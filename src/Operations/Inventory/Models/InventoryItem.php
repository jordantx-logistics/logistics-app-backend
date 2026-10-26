<?php

namespace Src\Operations\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_count_id',
        'shipment_id',
        'ticket_code',
        'expected',
        'found',
        'difference',
        'motive',
    ];

    protected function casts(): array
    {
        return [
            'expected' => 'boolean',
            'found' => 'boolean',
            'difference' => 'integer',
        ];
    }

    /** @return BelongsTo<InventoryCount, $this> */
    public function count(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }
}
