<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Src\Shared\Services\ProcessedEvents;

/**
 * Proyección de `order.created` hacia Operaciones (cola `sync`).
 *
 * Placeholder de la Fase 4: cuando exista el contexto `Operaciones`, este job
 * escribirá `shipment_projections`. Hoy solo deja constancia en el log, pero ya
 * ocupa su cola y es idempotente, que es lo que el outbox necesita que exista.
 */
final class ProjectOrderCreatedJob implements ShouldQueue
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
        $processed->once($this->outboxEventId, self::class, function () {
            Log::info('ops.projection_pending', [
                'order_id' => $this->payload['order_id'] ?? null,
                'phase' => 'fase-04',
            ]);
        });
    }
}
