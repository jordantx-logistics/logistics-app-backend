<?php

namespace Src\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca de "este handler ya procesó este evento del outbox".
 *
 * El outbox es at-least-once: un job puede reintentarse (o dos workers cogerlo
 * a la vez). El índice único `(outbox_event_id, handler)` es lo que hace que el
 * efecto se aplique una sola vez; ver `Src\Shared\Services\ProcessedEvents`.
 */
class ProcessedEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'outbox_event_id',
        'handler',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
