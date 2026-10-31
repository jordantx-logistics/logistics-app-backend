<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proyección de Core con el dinero cobrado de una orden.
 *
 * La alimentan `payment.registered`, `payment.refunded` y `payment.voided`.
 * `CancelOrderUseCase` la consulta para impedir cancelar una orden con cobros.
 */
class OrderPaymentProjection extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id',
        'paid_amount',
        'refunded_amount',
        'status',
        'last_event_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'last_event_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function netPaid(): string
    {
        $paid = (int) round(((float) $this->paid_amount) * 100);
        $refunded = (int) round(((float) $this->refunded_amount) * 100);

        return number_format(($paid - $refunded) / 100, 2, '.', '');
    }
}
