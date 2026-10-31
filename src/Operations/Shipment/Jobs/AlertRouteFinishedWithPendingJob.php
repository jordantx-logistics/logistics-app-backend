<?php

namespace Src\Operations\Shipment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Src\Operations\Shipment\Models\EmbarkItem;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Services\ProcessedEvents;

/**
 * `route.finished` con envíos sin desembarcar: alerta, no bloqueo. El vehículo ya
 * llegó y el desembarque puede registrarse después; el aviso permite detectar los
 * que se quedan olvidados.
 */
final class AlertRouteFinishedWithPendingJob implements ShouldQueue
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
            $ticketNumber = (string) ($this->payload['ticket_number'] ?? '');

            $pending = Shipment::query()
                ->whereIn('id', EmbarkItem::query()
                    ->whereHas('embark', fn ($embark) => $embark->where('route_ref', $ticketNumber))
                    ->select('shipment_id'))
                ->whereIn('status', [Shipment::STATUS_EMBARKED, Shipment::STATUS_IN_TRANSIT])
                ->count();

            if ($pending > 0) {
                Log::warning('fleet.route_finished_with_pending_shipments', [
                    'route_ref' => $ticketNumber,
                    'pending' => $pending,
                ]);
            }
        });
    }
}
