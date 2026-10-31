<?php

namespace Src\Fleet\Route\Models;

use Illuminate\Database\Eloquent\Model;

class MileageLog extends Model
{
    protected $fillable = [
        'route_id',
        'vehicle_id',
        'odometer_km',
        'recorded_at',
        'recorded_by',
        'photo_path',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'odometer_km' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }
}
