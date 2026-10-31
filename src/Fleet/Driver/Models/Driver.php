<?php

namespace Src\Fleet\Driver\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Ficha operativa del chofer. `user_id` referencia a Identity sin FK: la cuenta
 * con la que el chofer inicia sesión y opera sus rutas.
 */
class Driver extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];

    protected $fillable = [
        'user_id',
        'license_number',
        'license_category',
        'license_expires_at',
        'phone',
        'status',
        'home_agency_id',
    ];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
        ];
    }

    public function agencyScopeColumn(): string
    {
        return 'home_agency_id';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** La licencia sirve hasta el último día de su vigencia, inclusive. */
    public function licenseValidOn(CarbonInterface $date): bool
    {
        return $this->license_expires_at->copy()->startOfDay()->greaterThanOrEqualTo($date->copy()->startOfDay());
    }
}
