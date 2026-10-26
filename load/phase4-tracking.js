import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 4 — consulta histórica por `ticket_code`.
 *
 * Criterio de la fase: p95 de GET /operations/tracking/{ticketCode} <= 300 ms
 * sobre `tracking_events` particionada por mes.
 */
export const options = {
  scenarios: {
    tracking: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:tracking_timeline}': ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const TICKET_CODE = __ENV.TICKET_CODE || '';

export default function () {
  const response = http.get(
    `${BASE_URL}/api/v1/operations/tracking/${encodeURIComponent(TICKET_CODE)}`,
    {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${TOKEN}`,
      },
      tags: { name: 'tracking_timeline' },
    }
  );

  check(response, {
    'tracking -> 200': (r) => r.status === 200,
    'tracking -> events': (r) => Array.isArray(r.json('data.events')),
  });
}
