import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 6 — criterio 2: `route.started`/`route.finished` procesados <= 5 s p95.
 *
 * Genera rutas completas (planificar, asignar, iniciar, finalizar) con un solo
 * chofer y vehículo, así que va con un VU. La latencia se lee después con
 * `php artisan outbox:latency`, con un consumidor persistente.
 */
export const options = {
  vus: 1,
  duration: __ENV.DURATION || '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const params = {
  headers: {
    Authorization: `Bearer ${__ENV.TOKEN}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
};
const ORIGIN = Number(__ENV.ORIGIN_AGENCY_ID);
const DESTINATION = Number(__ENV.DESTINATION_AGENCY_ID);

let odometer = Number(__ENV.ODOMETER);

export default function () {
  const post = (path, body) => http.post(`${BASE_URL}/api/v1/fleet${path}`, JSON.stringify(body), params);

  const created = post('/routes', {
    origin_agency_id: ORIGIN,
    destination_agency_id: DESTINATION,
    planned_departure_at: new Date(Date.now() + 86400000).toISOString(),
    segments: [{ from_agency_id: ORIGIN, to_agency_id: DESTINATION, distance_km: 100 }],
  });
  const id = created.json('data.id');

  const assigned = post(`/routes/${id}/assign`, {
    driver_id: Number(__ENV.DRIVER_ID),
    vehicle_id: Number(__ENV.VEHICLE_ID),
  });
  const started = post(`/routes/${id}/start`, { odometer_km: odometer });

  odometer += 100;

  const finished = post(`/routes/${id}/finish`, { odometer_km: odometer });

  check(created, { 'created 201': (r) => r.status === 201 });
  check(assigned, { 'assigned 200': (r) => r.status === 200 });
  check(started, { 'started 200': (r) => r.status === 200 });
  check(finished, { 'finished 200': (r) => r.status === 200 });
}
