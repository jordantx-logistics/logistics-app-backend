<?php

namespace Src\Finance\Invoice\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Finance\Payment\Models\Payment;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Comprobante electrónico (boleta o factura).
 *
 * La fila nace `pending` en la transacción del pago, con serie y correlativo
 * reservados. El XML/PDF y el envío a SUNAT los produce el pipeline asíncrono.
 */
class Invoice extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const DOCUMENT_BOLETA = 'boleta';

    public const DOCUMENT_FACTURA = 'factura';

    public const DOCUMENT_TYPES = [self::DOCUMENT_BOLETA, self::DOCUMENT_FACTURA];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_VOIDED,
    ];

    protected $fillable = [
        'ticket_number',
        'order_id',
        'payment_id',
        'agency_id',
        'invoice_series_id',
        'document_type',
        'series',
        'number',
        'customer_document_type',
        'customer_document_number',
        'customer_name',
        'taxable_amount',
        'igv',
        'total',
        'currency',
        'status',
        'xml_path',
        'pdf_path',
        'cdr_path',
        'sunat_code',
        'sunat_message',
        'issued_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'taxable_amount' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function seriesConfig(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class, 'invoice_series_id');
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isVoidable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REJECTED], true);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @param  list<int>  $ids
     * @return Builder<Invoice>
     */
    public function scopeForAgencies(Builder $query, array $ids): Builder
    {
        return $query->whereIn('agency_id', $ids);
    }
}
