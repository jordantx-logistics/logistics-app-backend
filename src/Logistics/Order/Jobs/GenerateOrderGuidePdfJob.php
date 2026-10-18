<?php

namespace Src\Logistics\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Src\Shared\Services\ProcessedEvents;

/**
 * Guía de remisión de la orden (cola `default`).
 *
 * Fase 1 deja montado el camino completo —cola, idempotencia y artefacto en el
 * disco privado—, pero el documento definitivo es de la Fase 3: necesita el
 * origen/destino en el payload y el motor de plantillas/PDF por decidir. Hasta
 * entonces se guarda un borrador HTML con los datos que hoy emite
 * `order.created`.
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

    public function handle(ProcessedEvents $processed): void
    {
        $processed->once($this->outboxEventId, self::class, function () {
            Storage::disk(self::DISK)->put($this->path(), view('orders.guide', [
                'payload' => $this->payload,
            ])->render());
        });
    }

    /**
     * `orders/<order_id>/guia-<ticket_code>.html`. El `ticket_code` es único,
     * así que reejecutar el job no pisa la guía de otra orden.
     */
    public function path(): string
    {
        $orderId = (int) ($this->payload['order_id'] ?? 0);
        $ticket = (string) ($this->payload['ticket_code'] ?? 'sin-ticket');

        return "orders/{$orderId}/guia-{$ticket}.html";
    }
}
