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
     * `orders/<ticket_code>/guia-borrador.html`: la estructura que fija
     * `07-devops/08-respaldo-y-dr.md` para los documentos de una orden. El
     * `ticket_code` es único, así que dos órdenes no se pisan; y el nombre es
     * estable para que el documento definitivo lo sustituya sin mover rutas.
     */
    public function path(): string
    {
        $folder = (string) ($this->payload['ticket_code'] ?? $this->payload['order_id'] ?? 'sin-ticket');

        return "orders/{$folder}/guia-borrador.html";
    }
}
