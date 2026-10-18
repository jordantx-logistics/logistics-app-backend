<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Confirmación de envío</title>
</head>
<body>
    <p>Hemos registrado su envío.</p>

    <ul>
        <li>Orden: {{ $payload['ticket_number'] ?? '—' }}</li>
        <li>Código: {{ $payload['ticket_code'] ?? '—' }}</li>
        <li>Monto: {{ $payload['total_amount'] ?? '—' }}</li>
    </ul>
</body>
</html>
