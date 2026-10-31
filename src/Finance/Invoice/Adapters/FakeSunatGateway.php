<?php

namespace Src\Finance\Invoice\Adapters;

use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\DTOs\SunatResponse;
use Src\Finance\Invoice\Models\Invoice;

/**
 * Gateway SUNAT simulado.
 *
 * `SUNAT_FAKE_MODE=accepted|rejected` permite probar el camino feliz y el
 * rechazo con CDR sintético. No firma ni envía nada: la integración real queda
 * para cuando existan certificado y credenciales.
 */
class FakeSunatGateway implements SunatGateway
{
    public function sendInvoice(Invoice $invoice): SunatResponse
    {
        return $this->response((string) $invoice->ticket_number, 'invoice');
    }

    public function sendCreditNote(CreditNote $creditNote): SunatResponse
    {
        return $this->response((string) $creditNote->ticket_number, 'credit-note');
    }

    public function voidInvoice(Invoice $invoice): SunatResponse
    {
        $accepted = $this->accepted();

        return new SunatResponse(
            status: $accepted ? 'accepted' : 'rejected',
            code: $accepted ? '0' : '2335',
            message: $accepted ? 'Baja del comprobante aceptada.' : 'La baja fue rechazada por SUNAT.',
            cdr: $this->cdr((string) $invoice->ticket_number, $accepted),
        );
    }

    private function response(string $identifier, string $type): SunatResponse
    {
        $accepted = $this->accepted();

        return new SunatResponse(
            status: $accepted ? 'accepted' : 'rejected',
            code: $accepted ? '0' : '2335',
            message: $accepted
                ? "El {$type} {$identifier} ha sido aceptado."
                : "El {$type} {$identifier} fue rechazado: monto o documento inválido.",
            cdr: $this->cdr($identifier, $accepted),
        );
    }

    private function accepted(): bool
    {
        return strtolower((string) config('finance.sunat.mode', 'accepted')) === 'accepted';
    }

    private function cdr(string $identifier, bool $accepted): string
    {
        $status = $accepted ? '0' : '2335';
        $message = $accepted ? 'Aceptado' : 'Rechazado';

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"><ID>%s</ID><ResponseCode>%s</ResponseCode><Description>%s</Description></ApplicationResponse>',
            htmlspecialchars($identifier, ENT_XML1),
            $status,
            $message,
        );
    }
}
