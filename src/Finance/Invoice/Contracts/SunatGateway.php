<?php

namespace Src\Finance\Invoice\Contracts;

use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\DTOs\SunatResponse;
use Src\Finance\Invoice\Models\Invoice;

/**
 * Puerto de integración con SUNAT/PSE.
 *
 * El pipeline de Finanzas no conoce el proveedor: en Fase 5 se enlaza el fake;
 * el adaptador real se añadirá cuando existan certificado y credenciales.
 */
interface SunatGateway
{
    public function sendInvoice(Invoice $invoice): SunatResponse;

    public function sendCreditNote(CreditNote $creditNote): SunatResponse;

    public function voidInvoice(Invoice $invoice): SunatResponse;
}
