<?php

namespace Src\Finance\Payment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Proyección local de la orden para cobrar y liquidar.
 *
 * La puebla `order.created`; `delivery.completed` la marca lista para
 * liquidación y `order.cancelled` la cierra a nuevos cobros. No tiene FK a
 * Core (ADR-0006).
 */
class BillingOrder extends Model
{
    use CanBeScopedByAgency;

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'ticket_number',
        'ticket_code',
        'status',
        'origin_agency_id',
        'destination_agency_id',
        'sender_id',
        'receiver_id',
        'payer_id',
        'payer_name',
        'customer_document_type',
        'customer_document_number',
        'amount_due',
        'paid_amount',
        'refunded_amount',
        'payment_status',
        'currency',
        'payment_responsibility',
        'delivered_at',
        'ready_for_settlement',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'ready_for_settlement' => 'boolean',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isBillable(): bool
    {
        return $this->cancelled_at === null && $this->status !== 'cancelled';
    }

    /**
     * @param  Builder<BillingOrder>  $query
     * @param  list<int>  $ids
     * @return Builder<BillingOrder>
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
