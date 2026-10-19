<?php

namespace Src\Logistics\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
