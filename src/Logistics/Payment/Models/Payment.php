<?php

namespace Src\Logistics\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pago de una orden (contexto `Logistics/Payment`).
 *
 * `order_id` y `processed_by` apuntan a otros contextos (`Logistics/Order` y
 * `Auth`): columnas indexadas sin FK y sin relaciones Eloquent (ADR-0006).
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'ticket_code',
        'idempotency_key',
        'order_id',
        'amount',
        'payment_method',
        'reference',
        'status',
        'processed_by',
    ];
}
