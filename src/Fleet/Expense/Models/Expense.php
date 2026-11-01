<?php

namespace Src\Fleet\Expense\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Fleet\Route\Models\FleetRoute;

/**
 * Viático o combustible de una ruta. `receipt_path` nunca sale por la API: el
 * comprobante se descarga por su endpoint autenticado.
 */
class Expense extends Model
{
    use HasFactory;

    public const TYPE_PER_DIEM = 'per_diem';

    public const TYPE_FUEL = 'fuel';

    public const TYPES = [self::TYPE_PER_DIEM, self::TYPE_FUEL];

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_LIQUIDATED = 'liquidated';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'route_id',
        'driver_id',
        'vehicle_id',
        'type',
        'amount',
        'currency',
        'liters',
        'odometer_km',
        'receipt_path',
        'status',
        'rejection_reason',
        'registered_by',
        'registered_at',
        'reviewed_by',
        'reviewed_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'liters' => 'decimal:2',
            'odometer_km' => 'decimal:2',
            'registered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(FleetRoute::class, 'route_id');
    }
}
