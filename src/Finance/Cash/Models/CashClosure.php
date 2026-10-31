<?php

namespace Src\Finance\Cash\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre con descuadre pendiente de aprobación.
 *
 * El cierre cuadrado se resuelve en la misma petición y deja una fila
 * `approved`; si `difference != 0` se crea `pending` y la sesión no se cierra
 * hasta que un supervisor con `voids.approve` la apruebe.
 */
class CashClosure extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'cash_session_id',
        'status',
        'expected_amount',
        'closing_amount',
        'difference',
        'requested_by',
        'approved_by',
        'requested_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'report_path',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
