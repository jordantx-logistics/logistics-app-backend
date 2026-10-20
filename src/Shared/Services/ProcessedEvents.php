<?php

namespace Src\Shared\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Src\Shared\Models\ProcessedEvent;

/**
 * Idempotencia de los jobs del outbox.
 *
 * El outbox garantiza *at-least-once*: un job puede reintentarse y dos workers
 * pueden procesar el mismo evento a la vez. `once()` envuelve el efecto para
 * que se aplique una sola vez por `(evento, handler)`:
 *
 *  - el efecto y la marca se confirman en la misma transacción (si el efecto
 *    falla no se marca, y el reintento lo vuelve a intentar);
 *  - si dos workers entran a la vez, el índice único de `processed_events`
 *    rechaza la segunda marca.
 */
final class ProcessedEvents
{
    public function once(int $outboxEventId, string $handler, Closure $effect): void
    {
        DB::transaction(function () use ($outboxEventId, $handler, $effect) {
            if ($this->alreadyProcessed($outboxEventId, $handler)) {
                return;
            }

            $effect();

            ProcessedEvent::create([
                'outbox_event_id' => $outboxEventId,
                'handler' => $handler,
                'processed_at' => now(),
            ]);
        });
    }

    public function alreadyProcessed(int $outboxEventId, string $handler): bool
    {
        return ProcessedEvent::query()
            ->where('outbox_event_id', $outboxEventId)
            ->where('handler', $handler)
            ->exists();
    }
}
