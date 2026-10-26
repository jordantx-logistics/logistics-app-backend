<?php

namespace Src\Operations\Reception\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Traits\CanBeScopedByAgency;

class Reception extends Model
{
    use CanBeScopedByAgency, HasFactory;

    protected $fillable = [
        'agency_id',
        'received_by',
        'received_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /** @return HasMany<ReceptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReceptionItem::class)->orderBy('id');
    }
}
