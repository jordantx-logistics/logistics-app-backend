<?php

namespace Src\Finance\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Finance\Payment\UseCases\ProjectOrderUseCase;
use Src\Shared\Services\ProcessedEvents;

/**
 * Proyecta `order.cancelled`: la orden deja de ser cobrable.
 */
final class ProjectBillingOrderCancelledJob implements ShouldQueue
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
            fn () => $projector->cancelled($this->payload),
        );
    }
}
