<?php

namespace Src\Finance\Invoice\Services;

use Src\Finance\Invoice\Models\InvoiceSeries;

/**
 * Resuelve la serie activa por defecto de una agencia y tipo de comprobante.
 *
 * Si no hay serie, el pago no se puede registrar: no se inventa un correlativo.
 */
class InvoiceSeriesResolver
{
    public function resolve(int $agencyId, string $documentType): ?InvoiceSeries
    {
        return InvoiceSeries::query()
            ->where('agency_id', $agencyId)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
