<?php

namespace Src\Logistics\Order\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Src\Logistics\Order\Models\Order;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CustomerDirectory;

/**
 * Guía de remisión en PDF.
 *
 * Es el documento operativo mínimo que viaja con el paquete: ruta, remitente,
 * destinatario, medidas, reparto y el `ticket_code` en un QR para que la
 * agencia destino lo escanee y encuentre la orden
 * (`GET /api/v1/logistics/orders/by-ticket/{ticket_code}`).
 *
 * El QR se genera como SVG (sin GD/Imagick) y se incrusta como `data:` URI:
 * dompdf no sale a la red y el PDF queda autocontenido.
 *
 * Los nombres de cliente y agencia llegan por los puertos, no por modelos
 * ajenos (ADR-0006).
 */
class OrderGuideService
{
    public function __construct(
        private readonly CustomerDirectory $customers,
        private readonly AgencyDirectory $agencies,
    ) {}

    /**
     * PDF binario (`%PDF-…`).
     */
    public function render(Order $order): string
    {
        return Pdf::loadView('orders.guide', [
            'order' => $order,
            'sender' => $this->customers->customerDisplayName($order->sender_id) ?? "Cliente #{$order->sender_id}",
            'receiver' => $this->customers->customerDisplayName($order->receiver_id) ?? "Cliente #{$order->receiver_id}",
            'originAgency' => $this->agencies->agencyName($order->origin_agency_id) ?? "Agencia #{$order->origin_agency_id}",
            'destinationAgency' => $this->agencies->agencyName($order->destination_agency_id) ?? "Agencia #{$order->destination_agency_id}",
            'qr' => $this->qrDataUri($order->ticket_code),
            'generatedAt' => now(),
        ])->setPaper('a4')->output();
    }

    /**
     * QR del `ticket_code` como `data:image/svg+xml;base64,…`.
     */
    public function qrDataUri(string $value): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);

        $svg = (new Writer($renderer))->writeString($value);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
