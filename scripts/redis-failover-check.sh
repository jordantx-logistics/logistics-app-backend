#!/usr/bin/env sh
# Mata Redis con SIGKILL mientras entran órdenes y comprueba que todo se reanuda solo y que
# ninguna orden se queda sin su envío. Necesita el stack de escala (`scripts/scale-up.sh`).
# Consume mucha CPU y memoria, y corre en CI (job `scale`).
set -eu

compose="docker compose -f docker-compose.yml -f docker-compose.scale.yml --profile tools"
sql() { $compose exec -T mysql mysql -uroot -proot -N logistics_app -e "$1" 2>/dev/null; }

$compose run --rm -T -e LOAD_SHIPMENTS=500 -e LOAD_EVENTS=1000 tools \
  php artisan db:seed --class='Database\Seeders\LoadTestSeeder' --force

# El fallo de k6 durante el corte es esperado: lo que se comprueba es lo de después.
ONLY=counter FACTOR=0.4 DURATION=90s sh scripts/load-run.sh > k6-failover.log 2>&1 &
load=$!

sleep 30
echo "Matando Redis..."
$compose kill -s KILL redis
sleep 10
$compose start redis
echo "Redis de vuelta."

wait "$load" || true

failures=0
echo "Esperando a que se vacíe el outbox..."

for _ in $(seq 1 60); do
  pending=$(sql "SELECT COUNT(*) FROM outbox_events WHERE status IN ('pending', 'processing')")
  [ "$pending" = "0" ] && break
  sleep 5
done

# Margen para que los workers terminen lo que ya estaba en la cola.
sleep 30

orders=$(sql "SELECT COUNT(*) FROM orders")
missing=$(sql "SELECT COUNT(*) FROM orders o LEFT JOIN shipments s ON s.order_id = o.id WHERE s.id IS NULL")
pending=$(sql "SELECT COUNT(*) FROM outbox_events WHERE status IN ('pending', 'processing')")
failed=$(sql "SELECT COUNT(*) FROM outbox_events WHERE status = 'failed'")
failed_jobs=$(sql "SELECT COUNT(*) FROM failed_jobs")

echo "órdenes=$orders sin_envío=$missing outbox_pendientes=$pending outbox_fallidos=$failed failed_jobs=$failed_jobs"
grep -E 'http_req_failed|http_reqs' k6-failover.log || true
rm -f k6-failover.log

[ "$orders" -gt 0 ] || { echo "FALLO no entró ninguna orden"; failures=$((failures + 1)); }
[ "$pending" = "0" ] || { echo "FALLO el outbox no se vació"; failures=$((failures + 1)); }
[ "$failed" = "0" ] || { echo "FALLO hay eventos fallidos"; failures=$((failures + 1)); }
[ "$missing" = "0" ] || { echo "FALLO hay órdenes sin envío: se perdieron jobs"; failures=$((failures + 1)); }

if ! $compose exec -T horizon php artisan horizon:status > /dev/null 2>&1; then
  echo "FALLO Horizon no se recuperó"
  failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
  echo "$failures comprobaciones fallaron."
  exit 1
fi

echo "Todo correcto."
