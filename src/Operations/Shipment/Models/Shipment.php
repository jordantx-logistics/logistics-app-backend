<?php

namespace Src\Operations\Shipment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Services\ShipmentStatus;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Proyección y estado operativo de un envío.
 *
 * Nace en `created` cuando se consume `order.created` y avanza con las
 * operaciones físicas. Es la única tabla de proyección: no hay
 * `shipment_projections` separada.
 */
class Shipment extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const STATUS_CREATED = ShipmentStatus::CREATED;

    public const STATUS_RECEIVED = ShipmentStatus::RECEIVED;

    public const STATUS_EMBARKED = ShipmentStatus::EMBARKED;

    public const STATUS_IN_TRANSIT = ShipmentStatus::IN_TRANSIT;

    public const STATUS_DISEMBARKED = ShipmentStatus::DISEMBARKED;

    public const STATUS_OUT_FOR_DELIVERY = ShipmentStatus::OUT_FOR_DELIVERY;

    public const STATUS_DELIVERED = ShipmentStatus::DELIVERED;

    public const STATUS_DELIVERY_FAILED = ShipmentStatus::DELIVERY_FAILED;

    public const STATUS_RETURNED = ShipmentStatus::RETURNED;

    public const STATUS_CANCELLED = ShipmentStatus::CANCELLED;

    /** @var array<string, list<string>> */
    public const TRANSITIONS = ShipmentStatus::TRANSITIONS;

    protected $fillable = [
        'order_id',
        'ticket_code',
        'ticket_number',
        'origin_agency_id',
        'destination_agency_id',
        'current_location_agency_id',
        'status',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** @return HasMany<Embark, $this> */
    public function embarks(): HasMany
    {
        return $this->hasMany(Embark::class);
    }

    /**
     * El envío toca una agencia si es origen, destino o ubicación actual.
     *
     * @param  Builder<Shipment>  $query
     * @param  list<int>  $ids
     * @return Builder<Shipment>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereIn('origin_agency_id', $ids)
            ->orWhereIn('destination_agency_id', $ids)
            ->orWhereIn('current_location_agency_id', $ids));
    }
}
