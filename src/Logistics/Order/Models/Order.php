<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Traits\CanBeScopedByAgency;
use Src\Shared\Traits\HasTicketIdentifiers;

/**
 * Orden de envío (contexto `Logistics/Order`).
 *
 * `sender_id`, `receiver_id`, `origin_agency_id`, `destination_agency_id` y
 * `created_by` apuntan a otros contextos (`Customer`, `Organization`,
 * `Identity/User`): son columnas indexadas sin FK y sin relaciones Eloquent
 * (ADR-0006). Para leer esos datos se usa un puerto (`Src\Shared\Contracts`),
 * nunca el modelo ajeno.
 */
class Order extends Model
{
    use CanBeScopedByAgency, HasFactory, HasTicketIdentifiers;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    /** Transiciones válidas (`spec/04-modulos/core-logistica/02-entidades.md`). */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_IN_TRANSIT, self::STATUS_CANCELLED],
        self::STATUS_IN_TRANSIT => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const SERVICE_STANDARD = 'standard';

    public const SERVICE_EXPRESS = 'express';

    public const SERVICE_TYPES = [self::SERVICE_STANDARD, self::SERVICE_EXPRESS];

    public const PAYMENT_SENDER = 'sender';

    public const PAYMENT_RECEIVER = 'receiver';

    public const PAYMENT_RESPONSIBILITIES = [self::PAYMENT_SENDER, self::PAYMENT_RECEIVER];

    public const DELIVERY_AGENCY_PICKUP = 'agency_pickup';

    public const DELIVERY_HOME = 'home_delivery';

    public const DELIVERY_TYPES = [self::DELIVERY_AGENCY_PICKUP, self::DELIVERY_HOME];

    protected $fillable = [
        'idempotency_key',
        'sender_id',
        'receiver_id',
        'origin_agency_id',
        'destination_agency_id',
        'description',
        'weight_kg',
        'volume_m3',
        'chargeable_weight_kg',
        'service_type',
        'payment_responsibility',
        'tariff_id',
        'pricing_snapshot',
        'declared_value',
        'total_amount',
        'status',
        'delivery_type',
        'delivery_location_id',
        'recipient_address',
        'delivery_scheduled_at',
        'requires_signature',
        'delivery_attempts',
        'confirmed_at',
        'cancelled_at',
        'delivered_at',
        'cancelled_reason',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:4',
            'chargeable_weight_kg' => 'decimal:3',
            'declared_value' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'requires_signature' => 'boolean',
            'delivery_attempts' => 'integer',
            'version' => 'integer',
            'delivery_scheduled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderStatusHistory> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * ¿La transición está permitida por la máquina de estados?
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function getTicketPrefix(): string
    {
        return 'ORD';
    }

    /**
     * Acota la consulta a las órdenes que tocan alguna de esas agencias,
     * por origen o por destino (`spec/02-objetivo/04-autorizacion.md`, ámbito
     * por agencia).
     *
     * `Order` filtra por dos columnas, así que redefine el scope del trait
     * `CanBeScopedByAgency` en vez de declarar una sola columna; el resto de la
     * regla (nacional sin filtro, array vacío = no ver nada) es la del trait.
     *
     * @param  Builder<Order>  $query
     * @param  list<int>  $ids
     * @return Builder<Order>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->where(
            fn (Builder $query) => $query
                ->whereIn('origin_agency_id', $ids)
                ->orWhereIn('destination_agency_id', $ids),
        );
    }
}
