<?php

namespace Src\Finance\Invoice\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Finance\Invoice\Jobs\SendSunatJob;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Services\InvoiceArtifactService;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Reintenta la transmisión de un comprobante rechazado o pendiente.
 */
class RetrySunatTransmissionUseCase
{
    public function __construct(
        private readonly InvoiceArtifactService $artifacts,
    ) {}

    public function execute(int $invoiceId, AgencyScope $scope): Invoice
    {
        $invoice = DB::transaction(function () use ($invoiceId, $scope): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            if ($invoice === null) {
                throw new BusinessRuleException(
                    message: 'The invoice does not exist.',
                    errorCode: 'INVOICE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $invoice->agency_id)) {
                throw new BusinessRuleException(
                    message: 'The invoice belongs to an agency outside your scope.',
                    errorCode: 'CASH_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if ($invoice->status === Invoice::STATUS_ACCEPTED) {
                throw new BusinessRuleException(
                    message: 'The invoice was already accepted by SUNAT.',
                    errorCode: 'INVOICE_ALREADY_ACCEPTED',
                );
            }

            if ($invoice->status === Invoice::STATUS_VOIDED) {
                throw new BusinessRuleException(
                    message: 'A voided invoice cannot be transmitted again.',
                    errorCode: 'INVOICE_VOIDED',
                );
            }

            if ($invoice->xml_path === null || $invoice->pdf_path === null) {
                $invoice = $this->artifacts->generateInvoice($invoice);
            }

            return $invoice;
        });

        SendSunatJob::dispatch($invoice->id);

        return $invoice->refresh();
    }
}
