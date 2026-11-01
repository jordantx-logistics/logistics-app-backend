<?php

namespace Src\Incident\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Scope\AgencyScope;

/**
 * Incidencia sobre una orden, con responsable y plazo de atención.
 *
 * Guarda una foto del ticket y las agencias de la orden: el contexto no consulta
 * `orders` para filtrar por ámbito (ADR-0006).
 */
class Incident extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    /** Estados en los que el plazo sigue corriendo. */
    public const ACTIVE_STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    public const TYPES = ['damage', 'loss', 'delay', 'billing', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'order_id',
        'ticket_code',
        'origin_agency_id',
        'destination_agency_id',
        'type',
        'priority',
        'status',
        'description',
        'assigned_to',
        'due_at',
        'resolution_note',
        'resolved_at',
        'closed_at',
        'overdue_alerted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true) && $this->due_at->isPast();
    }

    /**
     * Igual que las órdenes: la ve quien cubre el origen o el destino.
     */
    public function scopeForScope(Builder $query, AgencyScope $scope): Builder
    {
        if ($scope->unrestricted) {
            return $query;
        }

        return $query->where(fn (Builder $visible) => $visible
            ->whereIn('origin_agency_id', $scope->ids)
            ->orWhereIn('destination_agency_id', $scope->ids));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)->where('due_at', '<', now());
    }
}
