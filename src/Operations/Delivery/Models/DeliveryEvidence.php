<?php

namespace Src\Operations\Delivery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvidence extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'signature_path',
        'photo_path',
        'latitude',
        'longitude',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
