<?php

namespace Src\Finance\Invoice\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Serie de comprobantes por agencia y tipo.
 *
 * `next_number` es el correlativo que se reservará en el próximo pago. La fila
 * se bloquea con `lockForUpdate()` durante la reserva para que dos cajas no
 * emitan el mismo número.
 */
class InvoiceSeries extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'document_type',
        'series',
        'next_number',
        'is_active',
        'is_default',
        'issuer_ruc',
        'issuer_name',
        'issuer_trade_name',
        'issuer_address',
        'issuer_ubigeo',
        'establishment_code',
    ];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @param  Builder<InvoiceSeries>  $query
     * @return Builder<InvoiceSeries>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
