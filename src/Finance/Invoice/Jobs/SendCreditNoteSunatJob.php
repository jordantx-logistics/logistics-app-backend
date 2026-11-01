<?php

namespace Src\Finance\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\Models\SunatTransmission;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Models\OutboxEvent;

/**
 * Envía una nota de crédito al gateway y guarda el CDR.
 */
final class SendCreditNoteSunatJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $creditNoteId,
    ) {
        $this->onQueue('high');
    }

    public function handle(SunatGateway $gateway, InvoiceArtifactService $artifacts): void
    {
        DB::transaction(function () use ($gateway, $artifacts): void {
            $note = CreditNote::query()->lockForUpdate()->find($this->creditNoteId);

            if ($note === null) {
                return;
            }

            if (in_array($note->status, [CreditNote::STATUS_ACCEPTED, CreditNote::STATUS_VOIDED], true)) {
                return;
            }

            $note->update(['status' => CreditNote::STATUS_SENT]);

            $attempt = (int) SunatTransmission::query()
                ->where('credit_note_id', $note->id)
                ->max('attempt') + 1;

            $response = $gateway->sendCreditNote($note);
            $cdrPath = $artifacts->storeCreditNoteCdr($note, $response, $attempt);

            SunatTransmission::create([
                'credit_note_id' => $note->id,
                'attempt' => $attempt,
                'endpoint' => (string) config('finance.sunat.endpoint'),
                'request_hash' => hash('sha256', (string) $note->xml_path.$note->ticket_number),
                'response_code' => $response->code,
                'response_message' => $response->message,
                'status' => $response->status,
                'created_at' => now(),
            ]);

            $note->update([
                'status' => $response->status,
                'sunat_code' => $response->code,
                'sunat_message' => $response->message,
                'cdr_path' => $cdrPath,
            ]);

            OutboxEvent::create([
                'event_type' => 'credit-note.'.$response->status,
                'payload' => [
                    'credit_note_id' => $note->id,
                    'payment_id' => $note->payment_id,
                    'order_id' => $note->order_id,
                    'ticket_number' => $note->ticket_number,
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
