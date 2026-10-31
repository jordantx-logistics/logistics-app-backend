# Medición de los SLO de Fase 3

Scripts de [k6](https://k6.io/) para las mediciones que exige el cierre de la
Fase 3. No se ejecutan en CI: necesitan la base de datos de desarrollo con
volumen sembrado y una agencia/tarifario resolubles.

## Preparación

1. Levanta el backend y siembra 100 000 órdenes:

   ```bash
   docker compose exec app php artisan migrate --seed
   docker compose exec app php artisan orders:seed-volume --count=100000 --explain
   ```

2. Obtén un token de un usuario con permisos (`admin`):

   ```bash
   curl -s -X POST http://localhost:8000/api/v1/auth/token \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@admin.pe","password":"SecureAdmin2026!@#","device_name":"k6"}'
   ```

   Copia `token` de la respuesta.

3. Ajusta los ids de remitente, destinatario y agencias a datos que existan
   (los del `.env.example` no sirven para todos los entornos).

## Ejecución

```bash
k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e SENDER_ID=1 -e RECEIVER_ID=2 \
  -e ORIGIN_AGENCY_ID=1 -e DESTINATION_AGENCY_ID=2 \
  load/phase3-create.js
```

Criterios:

- `phase3-create.js`: p95 de `POST /orders` ≤ 500 ms.
- `phase3-list.js`: p95 de `GET /orders` con 100k órdenes ≤ 300 ms.

```bash
k6 run -e BASE_URL=http://localhost:8000 -e TOKEN="$TOKEN" \
  -e STATUS=pending -e ORIGIN_AGENCY_ID=1 load/phase3-list.js
```

## Outbox y caché

- Latencia del outbox: `docker compose exec app php artisan outbox:latency --minutes=10`
  (p95 ≤ 5 s).
  - En Docker Desktop (Windows/macOS) el scheduler lanza un proceso PHP por
    pasada y cada arranque puede costar segundos por el *bind mount*: la
    latencia medida ahí no es representativa. Para medir el consumidor aislado,
    ejecuta un bucle persistente que arranque Laravel una sola vez
    (`php scripts/outbox-loop.php 300`) y lanza la carga contra él. En
    Linux/producción el arranque de `artisan` es de milisegundos.
- Hit ratio de tarifas: consulta `/metrics` antes y después de la carga y
  calcula el incremento de `tariff_cache_hits_total / (hits + misses)` (≥ 80 %).
  Los contadores no se reinician al recargar Octane; usa la diferencia entre dos
  scrapes, no el valor absoluto.

Guarda la salida de k6, del comando de outbox y de los dos scrapes de métricas
como evidencia del cierre.

## Fase 4 — Operaciones y Tracking

Los scripts `phase4-*.js` miden los SLO de la fase:

- `phase4-scan.js`: p95 de `POST /operations/scans/sync` ≤ 300 ms.
- `phase4-tracking.js`: p95 de `GET /operations/tracking/{ticketCode}` ≤ 300 ms.
- `phase4-shipments.js`: p95 del listado de envíos ≤ 300 ms (regresión de
  índices, no criterio explícito del roadmap).

Necesitan un token con `tracking.scan`/`tracking.view` y un `ticket_code` que
exista. Para reutilizar un envío ya creado:

```bash
docker compose exec app php artisan tinker --execute="echo Src\\Operations\\Shipment\\Models\\Shipment::query()->value('ticket_code');"
```

Los escaneos de carga usan `scan_type=inventory` a propósito: no mueven el
estado del envío y permiten repetir la medición sin volver a sembrar datos.

```bash
k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e TICKET_CODE=A7F3Q8X4 \
  load/phase4-scan.js

k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e TICKET_CODE=A7F3Q8X4 \
  load/phase4-tracking.js

k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e STATUS=received -e AGENCY=1 \
  load/phase4-shipments.js
```

La proyección `order.created` → `shipments` se mide con la latencia del outbox
del apartado anterior (`outbox:latency`), que compara `processed_at` con
`created_at`; el criterio de la fase es p95 ≤ 5 s. Para aislar el consumidor en
Docker Desktop, usa el bucle persistente `php scripts/outbox-loop.php 300`.

## Fase 5 — Finanzas

Los scripts `phase5-*.js` miden el SLO de la fase:

- `phase5-payment.js`: p95 de `POST /finance/payments` ≤ 500 ms.
- `phase5-cash.js`: p95 de `GET /finance/cash-sessions/current` ≤ 300 ms y del
  cierre de caja ≤ 500 ms. El cierre se hace una sola vez en el `teardown`;
  ejecútalo sin pagos en paralelo para que el esperado no cambie.

Preparación (base local recién migrada):

```bash
docker compose exec app php artisan db:seed --class=DemoDataSeeder
docker compose exec app php artisan db:seed --class=PermissionSeeder
docker compose exec app php artisan orders:seed-volume --count=20
```

El usuario del token necesita una agencia primaria (`agency_user.is_primary`)
para abrir caja, y la agencia necesita una serie fiscal activa. Con el admin de
demo:

```sql
INSERT INTO agency_user (user_id, agency_id, is_primary, created_at, updated_at)
VALUES (1, 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE is_primary = 1, updated_at = NOW();
```

Obtén el token y abre la sesión con `POST /api/v1/finance/cash-sessions`.
Después ejecuta la carga con una orden que tenga saldo y un importe pequeño
para que el mismo pedido soporte todas las iteraciones:

```bash
k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e ORDER_ID=37 \
  -e AMOUNT=0.01 \
  -e VUS=2 -e DURATION=15s \
  load/phase5-payment.js

k6 run \
  -e BASE_URL=http://localhost:8000 \
  -e TOKEN="$TOKEN" \
  -e VUS=2 -e DURATION=10s \
  load/phase5-cash.js
```

Con Docker Desktop, el contenedor de k6 llega al host por
`http://host.docker.internal:8000`:

```bash
docker run --rm -i \
  -e BASE_URL=http://host.docker.internal:8000 \
  -e TOKEN="$TOKEN" -e ORDER_ID=37 \
  -v "$PWD/load:/scripts" grafana/k6 run /scripts/phase5-payment.js
```

La emisión del comprobante (XML/PDF/CDR) y la transmisión SUNAT son jobs: la
respuesta del pago no las espera. La latencia del outbox se mide con
`outbox:latency` y el criterio de la fase es p95 ≤ 60 s para el comprobante
`accepted`/`rejected`.

Cada iteración suma un pago a la misma orden y `paidAmountOf` los recorre todos:
usa una orden con pocos pagos y espera a que el outbox se vacíe, o la medición
reflejará los datos de carga anteriores y no el caso de uso.

### Concurrencia de caja

No es k6: `scripts/finance-concurrency-check.php` lanza procesos reales que
ejecutan los casos de uso a la vez contra un MySQL desechable, con 10 rondas:

```bash
docker compose --profile concurrency up -d --wait mysql-concurrency

docker compose exec \
  -e DB_HOST=mysql-concurrency -e DB_DATABASE=logistics_concurrency \
  -e DB_USERNAME=root -e DB_PASSWORD=root \
  app php scripts/finance-concurrency-check.php 10

docker compose --profile concurrency rm -sf mysql-concurrency
```

