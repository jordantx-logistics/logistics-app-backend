<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Guía {{ $payload['ticket_number'] ?? '' }}</title>
</head>
<body>
    <h1>Guía de remisión (borrador)</h1>

    <ul>
        <li>Orden: {{ $payload['ticket_number'] ?? '—' }}</li>
        <li>Código: {{ $payload['ticket_code'] ?? '—' }}</li>
        <li>Monto: {{ $payload['total_amount'] ?? '—' }}</li>
    </ul>

    <p>Borrador de la Fase 1: el documento definitivo (origen, destino y formato
    PDF) llega con el core logístico.</p>
</body>
</html>
