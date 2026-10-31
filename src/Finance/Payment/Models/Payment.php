<?php

namespace Src\Finance\Payment\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Src\Finance\Cash\Models\CashSession;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Void\Models\PaymentVoid;
use Src\Shared\Traits\CanBeScopedByAgency;
use Src\Shared\Traits\HasTicketIdentifiers;

/**
 * Cobro de una orden (raíz del agregado Payment).
 *
 * `order_id` es una referencia indexada a Core (sin FK). `cash_session_id` sí
 * es FK intra-contexto: un pago siempre nace dentro de una sesión de caja.
 */
class Payment extends Model
{
    use CanBeScopedByAgency, HasFactory, HasTicketIdentifiers;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_VOIDED,
        self::STATUS_REFUNDED,
    ];

    public const METHOD_CASH = 'cash';

    public const METHOD_POS = 'pos';

    public const METHODS = [self::METHOD_CASH, self::METHOD_POS];

    public const DOCUMENT_BOLETA = 'boleta';

    public const DOCUMENT_FACTURA = 'factura';

    public const DOCUMENT_TYPES = [self::DOCUMENT_BOLETA, self::DOCUMENT_FACTURA];

    protected $fillable = [
        'ticket_number',
        'ticket_code',
        'idempotency_key',
        'order_id',
        'cash_session_id',
        'agency_id',
        'amount',
        'currency',
        'payment_method',
        'reference',
        'document_type',
        'status',
        'created_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function getTicketPrefix(): string
    {
        return 'PAY';
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function currentInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function voids(): HasMany
    {
        return $this->hasMany(PaymentVoid::class);
    }

    /**
     * Un pago en efectivo se puede reflejar en caja; POS no mueve el efectivo
     * esperado del turno.
     */
    public function movesCash(): bool
    {
        return $this->payment_method === self::METHOD_CASH;
    }

    /**
     * @param  Builder<Payment>  $query
     * @param  list<int>  $ids
     * @return Builder<Payment>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->whereIn('agency_id', $ids);
    }
}
