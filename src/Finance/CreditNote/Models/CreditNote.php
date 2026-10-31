<?php

namespace Src\Finance\CreditNote\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;
use Src\Shared\Traits\CanBeScopedByAgency;
use Src\Shared\Traits\HasTicketIdentifiers;

/**
 * Nota de crédito que revierte total o parcialmente un cobro.
 */
class CreditNote extends Model
{
    use CanBeScopedByAgency, HasFactory, HasTicketIdentifiers;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_VOIDED,
    ];

    /** Catálogo SUNAT de motivos admitidos en esta fase. */
    public const REASONS = ['01', '02', '03', '06', '07'];

    protected $fillable = [
        'ticket_number',
        'ticket_code',
        'order_id',
        'payment_id',
        'invoice_id',
        'agency_id',
        'cash_session_id',
        'amount',
        'currency',
        'reason',
        'note',
        'status',
        'created_by',
        'issued_at',
        'xml_path',
        'pdf_path',
        'cdr_path',
        'sunat_code',
        'sunat_message',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function getTicketPrefix(): string
    {
        return 'CRN';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
