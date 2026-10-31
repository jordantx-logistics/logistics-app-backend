<?php

namespace Src\Finance\Cash\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Src\Shared\Traits\CanBeScopedByAgency;

/**
 * Turno de caja de un cajero en una agencia.
 *
 * Una sesión nace `open` y solo se cierra por el caso de uso de cierre. Si el
 * cuadre tiene diferencia, queda un `CashClosure` pendiente y la sesión sigue
 * abierta hasta que un supervisor la aprueba.
 */
class CashSession extends Model
{
    use CanBeScopedByAgency, HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED];

    protected $fillable = [
        'agency_id',
        'cashier_id',
        'status',
        'opening_amount',
        'expected_amount',
        'closing_amount',
        'difference',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(CashClosure::class)->orderByDesc('id');
    }

    public function pendingClosure(): HasMany
    {
        return $this->hasMany(CashClosure::class)->where('status', CashClosure::STATUS_PENDING);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
