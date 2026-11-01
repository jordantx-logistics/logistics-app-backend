<?php

namespace Src\Operations\Tracking\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    public const TYPE_RECEPTION = 'reception';

    public const TYPE_EMBARK = 'embark';

    public const TYPE_DISEMBARK = 'disembark';

    public const TYPE_INVENTORY = 'inventory';

    public const TYPE_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const TYPE_DELIVERY = 'delivery';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_RECEPTION,
        self::TYPE_EMBARK,
        self::TYPE_DISEMBARK,
        self::TYPE_INVENTORY,
        self::TYPE_OUT_FOR_DELIVERY,
        self::TYPE_DELIVERY,
    ];

    protected $fillable = [
        'scan_id',
        'shipment_id',
        'embark_id',
        'scan_type',
        'device_id',
        'agency_id',
        'scanned_at',
        'synced_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
