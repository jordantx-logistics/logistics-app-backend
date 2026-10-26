<?php

namespace Src\Operations\Tracking\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingEvent extends Model
{
    /** Tabla append-only: solo tiene `created_at`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id',
        'order_id',
        'event_type',
        'status',
        'agency_id',
        'scan_id',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
