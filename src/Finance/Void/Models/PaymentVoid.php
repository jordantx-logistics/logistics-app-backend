<?php

namespace Src\Finance\Void\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Payment\Models\Payment;

/**
 * Solicitud de anulación de un pago.
 *
 * El nombre de la clase no es `Void` porque `void` es una palabra reservada del
 * lenguaje. La tabla se sigue llamando `voids`.
 */
class PaymentVoid extends Model
{
    use HasFactory;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $table = 'voids';

    protected $fillable = [
        'order_id',
        'payment_id',
        'invoice_id',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'requested_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }
}
