<?php

namespace Src\Fleet\Vehicle\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Unidad de transporte. `odometer_km` es la última lectura conocida: contra ella
 * se valida que el odómetro no retroceda.
 */
class Vehicle extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const TYPE_TRUCK = 'truck';

    public const TYPE_VAN = 'van';

    public const TYPE_TRAILER = 'trailer';

    public const TYPES = [self::TYPE_TRUCK, self::TYPE_VAN, self::TYPE_TRAILER];

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ON_ROUTE = 'on_route';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_INACTIVE = 'inactive';

    /** Estados que se fijan a mano; `on_route` solo lo mueve el ciclo de la ruta. */
    public const MANUAL_STATUSES = [self::STATUS_AVAILABLE, self::STATUS_MAINTENANCE, self::STATUS_INACTIVE];

    protected $fillable = [
        'plate',
        'type',
        'capacity_kg',
        'capacity_m3',
        'owner_agency_id',
        'status',
        'odometer_km',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:2',
            'capacity_m3' => 'decimal:2',
            'odometer_km' => 'decimal:2',
        ];
    }

    public function agencyScopeColumn(): string
    {
        return 'owner_agency_id';
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
}
