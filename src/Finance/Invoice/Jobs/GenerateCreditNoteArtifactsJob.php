<?php

namespace Src\Finance\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Services\ProcessedEvents;

/**
 * Genera XML y PDF de una nota de crédito emitida.
 */
final class GenerateCreditNoteArtifactsJob implements ShouldQueue
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
        $note = CreditNote::query()->find((int) ($this->payload['credit_note_id'] ?? 0));

        if ($note === null) {
            return;
        }

        $processed->once($this->outboxEventId, self::class, function () use ($note, $artifacts): void {
            $artifacts->generateCreditNote($note);
            SendCreditNoteSunatJob::dispatch($note->id);
        });
    }
}
