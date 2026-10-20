<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Guía {{ $order->ticket_number }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 2px; letter-spacing: 1px; }
        .muted { color: #555; font-size: 10px; }
        .header { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .qr { text-align: right; }
        .qr img { width: 110px; height: 110px; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 15px; letter-spacing: 2px; }
        table.box { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.box th, table.box td { border: 1px solid #999; padding: 5px 7px; text-align: left; vertical-align: top; }
        table.box th { background: #eee; width: 28%; font-weight: bold; }
        .badge { display: inline-block; border: 1px solid #111; padding: 3px 8px; font-weight: bold; }
        .notes { border: 1px dashed #999; padding: 6px 8px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>GUÍA DE REMISIÓN</h1>
                <div class="muted">{{ config('app.name') }} · Emitida {{ $generatedAt->format('d/m/Y H:i') }}</div>
                <div style="margin-top: 6px;">
                    <span class="badge">{{ $order->service_type }}</span>
                    <span class="badge">{{ $order->status }}</span>
                </div>
            </td>
            <td class="qr">
                <img src="{{ $qr }}" alt="QR {{ $order->ticket_code }}">
                <div class="code">{{ $order->ticket_code }}</div>
                <div class="muted">{{ $order->ticket_number }}</div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <th>Remitente</th>
            <td>{{ $sender }}</td>
        </tr>
        <tr>
            <th>Destinatario</th>
            <td>{{ $receiver }}</td>
        </tr>
        <tr>
            <th>Ruta</th>
            <td>{{ $originAgency }} &rarr; {{ $destinationAgency }}</td>
        </tr>
        <tr>
            <th>Descripción</th>
            <td>{{ $order->description ?: '—' }}</td>
        </tr>
        <tr>
            <th>Medidas</th>
            <td>
                Peso: {{ $order->weight_kg }} kg ·
                Volumen: {{ $order->volume_m3 ?? '—' }} m³ ·
                Facturable: {{ $order->chargeable_weight_kg ?? '—' }} kg
            </td>
        </tr>
        <tr>
            <th>Valor declarado</th>
            <td>S/ {{ $order->declared_value }}</td>
        </tr>
        <tr>
            <th>Pago</th>
            <td>
                {{ $order->payment_responsibility === 'receiver' ? 'Lo paga el destinatario' : 'Lo paga el remitente' }}
                · S/ {{ $order->total_amount }}
            </td>
        </tr>
    </table>

    <table class="box">
        <tr>
            <th>Entrega</th>
            <td>
                @if ($order->delivery_type === 'home_delivery')
                    Reparto a domicilio: {{ $order->recipient_address ?: '—' }}
                    @if ($order->delivery_scheduled_at)
                        · Ventana: {{ $order->delivery_scheduled_at->format('d/m/Y H:i') }}
                    @endif
                @else
                    Recojo en agencia destino
                @endif
            </td>
        </tr>
        <tr>
            <th>Firma requerida</th>
            <td>{{ $order->requires_signature ? 'Sí' : 'No' }}</td>
        </tr>
    </table>

    <div class="notes">
        Presente este código al recoger el envío. La agencia destino valida el QR
        contra el sistema; sin él no se entrega el paquete.
    </div>
</body>
</html>
