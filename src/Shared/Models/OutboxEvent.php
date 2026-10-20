<?php

namespace Src\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento del patrón outbox (`spec/02-objetivo/05-asincronia-y-eventos.md`).
 *
 * Un UseCase escribe la fila dentro de la misma transacción que el cambio de
 * dominio; el consumidor (`outbox:consume`) la publica en las colas. Nadie
 * despacha jobs directamente desde el UseCase (ADR-0006).
 */
class OutboxEvent extends Model
{
    use HasFactory;

    /** Lo primero que ve el consumidor. */
    public const STATUS_PENDING = 'pending';

    /** Reclamado por un consumidor que lo está publicando en las colas. */
    public const STATUS_PROCESSING = 'processing';

    /** Publicado en las colas; a partir de aquí reintenta el worker. */
    public const STATUS_PROCESSED = 'processed';

    /** Agotó los reintentos: se inspecciona y se reprocesa a mano. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_type',
        'payload',
        'status',
        'attempts',
        'error',
        'available_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
