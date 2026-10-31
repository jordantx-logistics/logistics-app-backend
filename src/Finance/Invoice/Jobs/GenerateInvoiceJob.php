<?php

namespace Src\Finance\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Services\ProcessedEvents;

/**
 * Genera XML y PDF de un comprobante creado en el alta del pago.
 *
 * `payment.registered` ya trae la fila `invoices` con serie y correlativo
 * reservados; aquí solo se materializan los artefactos y se encadena el envío
 * a SUNAT.
 */
final class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProcessedEvents $processed, InvoiceArtifactService $artifacts): void
    {
        $invoice = Invoice::query()
            ->where('payment_id', (int) ($this->payload['payment_id'] ?? 0))
            ->latest('id')
            ->first();

        if ($invoice === null) {
            return;
        }

        if (in_array($invoice->status, [Invoice::STATUS_ACCEPTED, Invoice::STATUS_VOIDED], true)) {
            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($invoice, $artifacts): void {
            $artifacts->generateInvoice($invoice);
            SendSunatJob::dispatch($invoice->id);
        });
    }
}
