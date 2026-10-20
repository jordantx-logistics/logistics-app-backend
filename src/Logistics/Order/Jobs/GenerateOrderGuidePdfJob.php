<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Src\Logistics\Order\Models\Order;
use Src\Logistics\Order\Services\OrderGuideService;
use Src\Shared\Services\ProcessedEvents;

/**
 * Guía de remisión de la orden en PDF (cola `default`).
 *
 * El PDF lo compone `OrderGuideService` (dompdf + QR del `ticket_code`) y se
 * guarda en `orders/<ticket_code>/guia.pdf`. `ProcessedEvents` hace el job
 * idempotente: un reintento del outbox no reescribe el documento.
 */
final class GenerateOrderGuidePdfJob implements ShouldQueue
{
    use Queueable;

    public const DISK = 'local';

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProcessedEvents $processed, OrderGuideService $guide): void
    {
        $order = Order::query()->find($this->payload['order_id'] ?? null);

        if ($order === null) {
            // La orden pudo borrarse entre el evento y el job; no hay nada que
            // emitir y reintentar no lo arregla.
            Log::warning('order_guide_order_missing', ['order_id' => $this->payload['order_id'] ?? null]);

            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($order, $guide): void {
            Storage::disk(self::DISK)->put($this->path(), $guide->render($order));
        });
    }

    /**
     * `orders/<ticket_code>/guia.pdf`: la estructura que fija
     * `07-devops/08-respaldo-y-dr.md` para los documentos de una orden.
     */
    public function path(): string
    {
        $folder = (string) ($this->payload['ticket_code'] ?? $this->payload['order_id'] ?? 'sin-ticket');

        return "orders/{$folder}/guia.pdf";
    }
}
