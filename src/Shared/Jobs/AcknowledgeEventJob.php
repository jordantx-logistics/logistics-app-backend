<?php

namespace Src\Shared\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Src\Shared\Services\ProcessedEvents;

/**
 * Reconocimiento temporal de eventos que todavía no tienen consumidor.
 *
 * El consumidor de outbox marca `failed` cualquier `event_type` sin job
 * registrado. Mientras un evento se publica para una fase posterior (por
 * ejemplo, `order.confirmed` espera a Operaciones), este job lo deja
 * `processed` y registra el hecho, en vez de llenar el outbox de fallos que no
 * se pueden arreglar reintentando.
 *
 * Es un puente explícito: cuando llegue el consumidor real, se reemplaza su
 * entrada en `DomainServiceProvider` y este job deja de usarse para ese evento.
 * La idempotencia es la misma que la de cualquier otro job del outbox.
 */
final class AcknowledgeEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProcessedEvents $processed): void
    {
        $processed->once($this->outboxEventId, self::class, function (): void {
            Log::info('outbox.acknowledged_without_consumer', [
                'order_id' => $this->payload['order_id'] ?? null,
                'ticket_code' => $this->payload['ticket_code'] ?? null,
            ]);
        });
    }
}
