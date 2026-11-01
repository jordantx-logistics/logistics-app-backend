<?php

namespace Src\Finance\Invoice\Services;

use Src\Finance\Payment\Models\Payment;
use Src\Shared\DTOs\FiscalDocument;

/**
 * Decide boleta o factura a partir del documento fiscal del pagador.
 *
 * La factura exige RUC. La boleta admite DNI o consumidor final.
 */
class DocumentTypeResolver
{
    public function resolve(?string $requested, ?FiscalDocument $fiscal): string
    {
        if ($requested !== null && $requested !== '') {
            return $requested;
        }

        return $fiscal?->isCompany() ? Payment::DOCUMENT_FACTURA : Payment::DOCUMENT_BOLETA;
    }
}
