<?php

namespace Src\Finance\Cash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de una sesión de caja. El signo lo determina `type`, no `amount`.
 */
class CashMovement extends Model
{
    use HasFactory;

    public const TYPE_OPENING = 'opening';

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_CLOSING = 'closing';

    public const TYPES = [
        self::TYPE_OPENING,
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
        self::TYPE_WITHDRAWAL,
        self::TYPE_CLOSING,
    ];

    public const OUTGOING = [self::TYPE_EXPENSE, self::TYPE_WITHDRAWAL];

    protected $fillable = [
        'cash_session_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    /**
     * Aporte del movimiento al efectivo esperado del turno.
     */
    public function signedAmount(): string
    {
        if (in_array($this->type, self::OUTGOING, true)) {
            return '-'.$this->amount;
        }

        return (string) $this->amount;
    }

    public function isOutgoing(): bool
    {
        return in_array($this->type, self::OUTGOING, true);
    }
}
