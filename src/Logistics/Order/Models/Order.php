<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Traits\HasTicketIdentifiers;

/**
 * Orden de envío (contexto `Logistics/Order`).
 *
 * `sender_id`, `receiver_id`, `origin_agency_id`, `destination_agency_id` y
 * `created_by` apuntan a otros contextos (`Customer`, `Organization`, `Auth`):
 * son columnas indexadas sin FK y sin relaciones Eloquent (ADR-0006). Para leer
 * esos datos se usa un puerto (`Src\Shared\Contracts`), nunca el modelo ajeno.
 */
class Order extends Model
{
    use HasFactory, HasTicketIdentifiers;

    protected $fillable = [
        'idempotency_key',
        'sender_id',
        'receiver_id',
        'origin_agency_id',
        'destination_agency_id',
        'description',
        'weight_kg',
        'volume_m3',
        'declared_value',
        'total_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:4',
            'declared_value' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function getTicketPrefix(): string
    {
        return 'ORD';
    }
}
