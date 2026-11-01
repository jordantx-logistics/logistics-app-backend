<?php

namespace Src\Finance\Invoice\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Src\Finance\CreditNote\Models\CreditNote;
use Src\Finance\Invoice\DTOs\SunatResponse;
use Src\Finance\Invoice\Models\Invoice;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Finance\Payment\Models\Payment;
use Src\Shared\Contracts\AgencyDirectory;

/**
 * Compone y guarda los artefactos fiscales (XML UBL 2.1, PDF y CDR).
 *
 * El XML es el mínimo viable para un comprobante electrónico peruano: no lleva
 * firma digital real porque en Fase 5 no hay certificado. El PDF y el CDR son
 * los que permiten operar y auditar mientras se integra el proveedor real.
 */
class InvoiceArtifactService
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    public function generateInvoice(Invoice $invoice): Invoice
    {
        $invoice->loadMissing(['payment', 'seriesConfig']);

        /** @var Payment $payment */
        $payment = $invoice->payment;
        $series = $invoice->seriesConfig;

        $agencyCode = $this->agencyCode((int) $invoice->agency_id);
        $date = ($invoice->issued_at ?? now());
        $folder = sprintf(
            '%s/%s/%s/%s',
            trim((string) config('finance.invoice_path', 'finance/invoices'), '/'),
            $agencyCode,
            $date->format('Y'),
            $date->format('m'),
        );

        $xml = $this->invoiceXml($invoice, $payment, $series);
        $pdf = $this->invoicePdf($invoice, $payment, $series);

        $base = $folder.'/'.$invoice->ticket_number;

        $this->put($base.'.xml', $xml);
        $this->put($base.'.pdf', $pdf);

        $invoice->update([
            'xml_path' => $base.'.xml',
            'pdf_path' => $base.'.pdf',
            'issued_at' => $invoice->issued_at ?? now(),
        ]);

        return $invoice->refresh();
    }

    public function generateCreditNote(CreditNote $creditNote): CreditNote
    {
        $creditNote->loadMissing(['payment', 'invoice']);

        $agencyCode = $this->agencyCode((int) $creditNote->agency_id);
        $date = $creditNote->issued_at ?? now();
        $folder = sprintf(
            '%s/%s/%s/%s',
            trim((string) config('finance.credit_note_path', 'finance/credit-notes'), '/'),
            $agencyCode,
            $date->format('Y'),
            $date->format('m'),
        );

        $xml = $this->creditNoteXml($creditNote);
        $pdf = $this->creditNotePdf($creditNote);

        $base = $folder.'/'.$creditNote->ticket_number;

        $this->put($base.'.xml', $xml);
        $this->put($base.'.pdf', $pdf);

        $creditNote->update([
            'xml_path' => $base.'.xml',
            'pdf_path' => $base.'.pdf',
        ]);

        return $creditNote->refresh();
    }

    public function storeInvoiceCdr(Invoice $invoice, SunatResponse $response, int $attempt): ?string
    {
        if ($response->cdr === null) {
            return null;
        }

        $path = $this->cdrPath(
            (string) config('finance.invoice_path', 'finance/invoices'),
            (int) $invoice->agency_id,
            (string) $invoice->ticket_number,
            $attempt,
        );

        $this->put($path, $response->cdr);

        return $path;
    }

    public function storeCreditNoteCdr(CreditNote $creditNote, SunatResponse $response, int $attempt): ?string
    {
        if ($response->cdr === null) {
            return null;
        }

        $path = $this->cdrPath(
            (string) config('finance.credit_note_path', 'finance/credit-notes'),
            (int) $creditNote->agency_id,
            (string) $creditNote->ticket_number,
            $attempt,
        );

        $this->put($path, $response->cdr);

        return $path;
    }

    public function download(string $path): mixed
    {
        return Storage::disk($this->disk())->download($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk())->exists($path);
    }

    private function cdrPath(string $root, int $agencyId, string $ticket, int $attempt): string
    {
        $date = now();
        $agencyCode = $this->agencyCode($agencyId);

        return sprintf(
            '%s/%s/%s/%s/%s-cdr-%d.xml',
            trim($root, '/'),
            $agencyCode,
            $date->format('Y'),
            $date->format('m'),
            $ticket,
            $attempt,
        );
    }

    private function invoiceXml(Invoice $invoice, Payment $payment, ?InvoiceSeries $series): string
    {
        $supplierRuc = $this->xml($series?->issuer_ruc ?? '');
        $supplierName = $this->xml($series?->issuer_name ?? '');
        $supplierAddress = $this->xml($series?->issuer_address ?? '');
        $supplierUbigeo = $this->xml($series?->issuer_ubigeo ?? '');
        $customerName = $this->xml((string) ($invoice->customer_name ?? $payment->reference ?? 'Cliente varios'));
        $customerType = $this->xml((string) ($invoice->customer_document_type ?? 'DOC'));
        $customerNumber = $this->xml((string) ($invoice->customer_document_number ?? '00000000'));
        $typeCode = $invoice->document_type === Payment::DOCUMENT_FACTURA ? '01' : '03';
        $currency = $this->xml((string) $invoice->currency);
        $issued = ($invoice->issued_at ?? now())->format('Y-m-d');
        $ticket = $this->xml((string) $invoice->ticket_number);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:tc:UBL:2.1"
    xmlns:cac="urn:oasis:names:tc:UBL:2.1"
    xmlns:cbc="urn:oasis:names:tc:UBL:2.1"
    xmlns:ext="urn:oasis:names:tc:UBL:2.1">
  <ext:UBLExtensions>
    <ext:UBLExtension>
      <ext:ExtensionContent>
        <!-- Fase 5: sin firma digital real; el adaptador SUNAT queda pendiente. -->
      </ext:ExtensionContent>
    </ext:UBLExtension>
  </ext:UBLExtensions>
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:CustomizationID>2.0</cbc:CustomizationID>
  <cbc:ID>{$ticket}</cbc:ID>
  <cbc:IssueDate>{$issued}</cbc:IssueDate>
  <cbc:InvoiceTypeCode>{$typeCode}</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>{$currency}</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="6">{$supplierRuc}</cbc:ID></cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>{$supplierName}</cbc:RegistrationName>
        <cac:RegisteredAddress><cbc:AddressLine><cbc:Line>{$supplierAddress}</cbc:Line></cbc:AddressLine></cac:RegisteredAddress>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="{$customerType}">{$customerNumber}</cbc:ID></cac:PartyIdentification>
      <cac:PartyLegalEntity><cbc:RegistrationName>{$customerName}</cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingCustomerParty>
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="{$currency}">{$invoice->igv}</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount currencyID="{$currency}">{$invoice->taxable_amount}</cbc:TaxableAmount>
      <cbc:TaxAmount currencyID="{$currency}">{$invoice->igv}</cbc:TaxAmount>
      <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>18</cbc:Percent></cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal>
    <cbc:LineExtensionAmount currencyID="{$currency}">{$invoice->taxable_amount}</cbc:LineExtensionAmount>
    <cbc:TaxInclusiveAmount currencyID="{$currency}">{$invoice->total}</cbc:TaxInclusiveAmount>
    <cbc:PayableAmount currencyID="{$currency}">{$invoice->total}</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
  <cac:InvoiceLine>
    <cbc:ID>1</cbc:ID>
    <cbc:InvoicedQuantity unitCode="NIU">1</cbc:InvoicedQuantity>
    <cbc:LineExtensionAmount currencyID="{$currency}">{$invoice->taxable_amount}</cbc:LineExtensionAmount>
    <cac:PricingReference><cac:AlternativeConditionPrice><cbc:PriceAmount currencyID="{$currency}">{$invoice->total}</cbc:PriceAmount><cbc:PriceTypeCode>01</cbc:PriceTypeCode></cac:AlternativeConditionPrice></cac:PricingReference>
    <cac:TaxTotal><cbc:TaxAmount currencyID="{$currency}">{$invoice->igv}</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="{$currency}">{$invoice->taxable_amount}</cbc:TaxableAmount><cbc:TaxAmount currencyID="{$currency}">{$invoice->igv}</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>18</cbc:Percent><cbc:TaxExemptionReasonCode>10</cbc:TaxExemptionReasonCode></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>
    <cac:Item><cbc:Description>Servicio de transporte de encomienda</cbc:Description></cac:Item>
    <cac:Price><cbc:PriceAmount currencyID="{$currency}">{$invoice->taxable_amount}</cbc:PriceAmount></cac:Price>
  </cac:InvoiceLine>
</Invoice>
XML;
    }

    private function invoicePdf(Invoice $invoice, Payment $payment, ?InvoiceSeries $series): string
    {
        $title = $invoice->document_type === Payment::DOCUMENT_FACTURA ? 'FACTURA ELECTRÓNICA' : 'BOLETA ELECTRÓNICA';
        $issuer = $this->html($series?->issuer_name ?? '');
        $ruc = $this->html($series?->issuer_ruc ?? '');
        $address = $this->html($series?->issuer_address ?? '');
        $customer = $this->html((string) ($invoice->customer_name ?? 'Cliente varios'));
        $document = $this->html(trim((string) $invoice->customer_document_type.' '.(string) $invoice->customer_document_number));

        return $this->pdfHtml($title, $invoice->ticket_number, [
            ['Emisor', $issuer],
            ['RUC', $ruc],
            ['Dirección', $address],
            ['Cliente', $customer],
            ['Documento', $document],
            ['Moneda', (string) $invoice->currency],
            ['Valor de venta', 'S/ '.$invoice->taxable_amount],
            ['IGV (18%)', 'S/ '.$invoice->igv],
            ['Total', 'S/ '.$invoice->total],
            ['Estado SUNAT', (string) $invoice->status],
        ], 'Servicio de transporte de encomienda');
    }

    private function creditNoteXml(CreditNote $creditNote): string
    {
        $invoice = $creditNote->invoice;
        $series = $invoice?->seriesConfig;
        $currency = $this->xml((string) $creditNote->currency);
        $issued = ($creditNote->issued_at ?? now())->format('Y-m-d');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CreditNote xmlns="urn:oasis:names:tc:UBL:2.1"
    xmlns:cac="urn:oasis:names:tc:UBL:2.1"
    xmlns:cbc="urn:oasis:names:tc:UBL:2.1">
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:ID>{$this->xml((string) $creditNote->ticket_number)}</cbc:ID>
  <cbc:IssueDate>{$issued}</cbc:IssueDate>
  <cbc:DocumentCurrencyCode>{$currency}</cbc:DocumentCurrencyCode>
  <cac:DiscrepancyResponse>
    <cbc:ReferenceID>{$this->xml((string) ($invoice?->ticket_number ?? ''))}</cbc:ReferenceID>
    <cbc:ResponseCode>{$this->xml((string) $creditNote->reason)}</cbc:ResponseCode>
    <cbc:Description>{$this->xml((string) ($creditNote->note ?? 'Nota de crédito'))}</cbc:Description>
  </cac:DiscrepancyResponse>
  <cac:BillingReference>
    <cac:InvoiceDocumentReference>
      <cbc:ID>{$this->xml((string) ($invoice?->ticket_number ?? ''))}</cbc:ID>
    </cac:InvoiceDocumentReference>
  </cac:BillingReference>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="6">{$this->xml((string) ($series?->issuer_ruc ?? ''))}</cbc:ID></cac:PartyIdentification>
      <cac:PartyLegalEntity><cbc:RegistrationName>{$this->xml((string) ($series?->issuer_name ?? ''))}</cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty>
    <cac:Party>
      <cac:PartyIdentification><cbc:ID schemeID="{$this->xml((string) ($invoice?->customer_document_type ?? 'DOC'))}">{$this->xml((string) ($invoice?->customer_document_number ?? '00000000'))}</cbc:ID></cac:PartyIdentification>
      <cac:PartyLegalEntity><cbc:RegistrationName>{$this->xml((string) ($invoice?->customer_name ?? 'Cliente varios'))}</cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingCustomerParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="{$currency}">{$creditNote->amount}</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</CreditNote>
XML;
    }

    private function creditNotePdf(CreditNote $creditNote): string
    {
        $invoice = $creditNote->invoice;
        $series = $invoice?->seriesConfig;

        return $this->pdfHtml('NOTA DE CRÉDITO ELECTRÓNICA', $creditNote->ticket_number, [
            ['Emisor', $this->html($series?->issuer_name ?? '')],
            ['RUC', $this->html($series?->issuer_ruc ?? '')],
            ['Comprobante', $this->html((string) ($invoice?->ticket_number ?? ''))],
            ['Cliente', $this->html((string) ($invoice?->customer_name ?? ''))],
            ['Motivo', $this->html((string) $creditNote->reason.' '.$creditNote->note)],
            ['Moneda', (string) $creditNote->currency],
            ['Importe', 'S/ '.$creditNote->amount],
            ['Estado SUNAT', (string) $creditNote->status],
        ], 'Nota de crédito');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function pdfHtml(string $title, string $number, array $rows, string $concept): string
    {
        $html = '<h1>'.$this->html($title).'</h1>';
        $html .= '<p class="number">'.$this->html($number).'</p>';
        $html .= '<table>';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th>'.$this->html($label).'</th><td>'.$this->html($value).'</td></tr>';
        }

        $html .= '</table><p class="concept">'.$this->html($concept).'</p>';
        $html .= '<p class="legal">Representación impresa del comprobante electrónico. Sin firma digital en Fase 5.</p>';

        return Pdf::loadHTML($this->wrapHtml($html))->setPaper('a4')->output();
    }

    private function wrapHtml(string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
            h1{font-size:16px;margin:0 0 4px}
            .number{font-size:14px;font-weight:bold;margin:0 0 16px}
            table{width:100%;border-collapse:collapse;margin-bottom:16px}
            th{width:35%;text-align:left;padding:6px;background:#f4f4f4;border:1px solid #ddd}
            td{padding:6px;border:1px solid #ddd}
            .concept{font-weight:bold}
            .legal{font-size:10px;color:#666;margin-top:24px}
        </style></head><body>'.$body.'</body></html>';
    }

    private function put(string $path, string $contents): void
    {
        Storage::disk($this->disk())->put($path, $contents);
    }

    private function disk(): string
    {
        return (string) config('finance.disk', 'local');
    }

    /**
     * Código corto de la agencia; usa un fallback estable si falta para no
     * dejar el comprobante sin ruta de almacenamiento.
     */
    public function agencyCode(int $agencyId): string
    {
        return $this->agencies->agencyCode($agencyId) ?? 'AG'.$agencyId;
    }

    private function xml(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function html(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
