<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Logistics\Order\Services\OrderPaymentProjector;
use Src\Shared\Services\ProcessedEvents;

/**
 * Consume `payment.registered` en Core.
 */
final class ProjectPaymentRegisteredJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('sync');
    }

    public function handle(ProcessedEvents $processed, OrderPaymentProjector $projector): void
    {
        $processed->once(
            $this->outboxEventId,
            self::class,
            fn () => $projector->registered($this->payload),
        );
    }
}
