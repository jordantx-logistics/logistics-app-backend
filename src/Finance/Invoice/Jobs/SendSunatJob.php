<?php

namespace Src\Finance\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\SunatTransmission;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Models\OutboxEvent;

/**
 * Envía un comprobante al gateway SUNAT y persiste el CDR y la respuesta.
 *
 * La llamada externa se hace con la fila bloqueada para que dos reintentos
 * simultáneos no abran dos transmisiones del mismo comprobante.
 */
final class SendSunatJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $invoiceId,
    ) {
        $this->onQueue('high');
    }

    public function handle(SunatGateway $gateway, InvoiceArtifactService $artifacts): void
    {
        DB::transaction(function () use ($gateway, $artifacts): void {
            $invoice = Invoice::query()->lockForUpdate()->find($this->invoiceId);

            if ($invoice === null) {
                return;
            }

            if (in_array($invoice->status, [Invoice::STATUS_ACCEPTED, Invoice::STATUS_VOIDED], true)) {
                return;
            }

            $invoice->update(['status' => Invoice::STATUS_SENT]);

            $attempt = (int) SunatTransmission::query()
                ->where('invoice_id', $invoice->id)
                ->max('attempt') + 1;

            $response = $gateway->sendInvoice($invoice);
            $cdrPath = $artifacts->storeInvoiceCdr($invoice, $response, $attempt);

            SunatTransmission::create([
                'invoice_id' => $invoice->id,
                'attempt' => $attempt,
                'endpoint' => (string) config('finance.sunat.endpoint'),
                'request_hash' => hash('sha256', (string) $invoice->xml_path.$invoice->ticket_number),
                'response_code' => $response->code,
                'response_message' => $response->message,
                'status' => $response->status,
                'created_at' => now(),
            ]);

            $invoice->update([
                'status' => $response->status,
                'sunat_code' => $response->code,
                'sunat_message' => $response->message,
                'cdr_path' => $cdrPath,
                'accepted_at' => $response->status === Invoice::STATUS_ACCEPTED ? now() : null,
            ]);

            OutboxEvent::create([
                'event_type' => 'invoice.'.$response->status,
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
