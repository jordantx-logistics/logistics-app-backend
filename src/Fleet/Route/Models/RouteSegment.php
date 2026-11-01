<?php

namespace Src\Fleet\Route\Models;

use Illuminate\Database\Eloquent\Model;

class RouteSegment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'route_id',
        'sequence',
        'from_agency_id',
        'to_agency_id',
        'distance_km',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'distance_km' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }
}
