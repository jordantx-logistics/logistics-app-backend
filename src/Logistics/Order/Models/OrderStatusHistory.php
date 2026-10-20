<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoría de transiciones de estado de una orden.
 *
 * Se escribe en la misma transacción que el cambio de `orders.status`: el
 * histórico es la fuente para responder «quién pasó esta orden a cancelada y
 * por qué» sin depender de los logs.
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    /** El histórico se llama `order_status_history` (singular), no `_histories`. */
    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'reason', 'changed_by'];

    /** @return BelongsTo<Order, OrderStatusHistory> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
