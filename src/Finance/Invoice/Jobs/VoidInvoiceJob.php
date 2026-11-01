<?php

namespace Src\Finance\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\SunatTransmission;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\ProcessedEvents;

/**
 * Comunica la baja de un comprobante no aceptado tras aprobar la anulación.
 *
 * Si el comprobante estaba aceptado, la anulación no pudo aprobarse sin una
 * nota de crédito; este job no lo toca.
 */
final class VoidInvoiceJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $outboxEventId,
        public readonly array $payload,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        ProcessedEvents $processed,
        SunatGateway $gateway,
        InvoiceArtifactService $artifacts,
    ): void {
        $invoice = Invoice::query()
            ->where('payment_id', (int) ($this->payload['payment_id'] ?? 0))
            ->latest('id')
            ->first();

        if ($invoice === null || $invoice->isAccepted()) {
            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($invoice, $gateway, $artifacts): void {
            $attempt = (int) SunatTransmission::query()
                ->where('invoice_id', $invoice->id)
                ->max('attempt') + 1;

            $response = $gateway->voidInvoice($invoice);
            $cdrPath = $artifacts->storeInvoiceCdr($invoice, $response, $attempt);

            SunatTransmission::create([
                'invoice_id' => $invoice->id,
                'attempt' => $attempt,
                'endpoint' => (string) config('finance.sunat.endpoint').'/void',
                'request_hash' => hash('sha256', 'void|'.$invoice->ticket_number),
                'response_code' => $response->code,
                'response_message' => $response->message,
                'status' => $response->status,
                'created_at' => now(),
            ]);

            $invoice->update([
                'status' => $response->status === 'accepted' ? Invoice::STATUS_VOIDED : $invoice->status,
                'sunat_code' => $response->code,
                'sunat_message' => $response->message,
                'cdr_path' => $cdrPath ?? $invoice->cdr_path,
            ]);

            OutboxEvent::create([
                'event_type' => $response->status === 'accepted' ? 'invoice.voided' : 'invoice.void-rejected',
                'payload' => [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $invoice->payment_id,
                    'order_id' => $invoice->order_id,
                    'ticket_number' => $invoice->ticket_number,
                    'status' => $response->status,
                    'code' => $response->code,
                    'message' => $response->message,
                    'attempt' => $attempt,
                    'processed_at' => now()->toIso8601String(),
                ],
            ]);
        });
    }
}
