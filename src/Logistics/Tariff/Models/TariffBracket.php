<?php

namespace Src\Logistics\Tariff\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo de un tarifario (`from_kg`–`to_kg`).
 *
 * Si el tarifario tiene tramos, el motor calcula el flete como
 * `fixed_fee + (peso_facturable - from_kg) * price_per_kg` del tramo que
 * contiene el peso; si no tiene, aplica `tariff.price_per_kg` sobre el peso
 * completo. Los tramos no se solapan y cubren como máximo hasta
 * `tariff.max_weight_kg` (`ValidateTariffDefinition`).
 */
class TariffBracket extends Model
{
    use HasFactory;

    protected $fillable = ['tariff_id', 'from_kg', 'to_kg', 'fixed_fee', 'price_per_kg'];

    protected function casts(): array
    {
        return [
            'from_kg' => 'decimal:3',
            'to_kg' => 'decimal:3',
            'fixed_fee' => 'decimal:2',
            'price_per_kg' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Tariff, TariffBracket> */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }
}
