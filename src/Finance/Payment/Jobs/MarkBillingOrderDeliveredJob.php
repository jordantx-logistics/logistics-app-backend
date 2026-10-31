<?php

namespace Src\Finance\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Finance\Payment\UseCases\ProjectOrderUseCase;
use Src\Shared\Services\ProcessedEvents;

/**
 * Proyecta `delivery.completed`: la orden queda entregada y lista para
 * liquidación. No genera asientos ni movimientos de caja.
 */
final class MarkBillingOrderDeliveredJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProcessedEvents $processed, ProjectOrderUseCase $projector): void
    {
        $processed->once(
            $this->outboxEventId,
            self::class,
            fn () => $projector->delivered($this->payload),
        );
    }
}
