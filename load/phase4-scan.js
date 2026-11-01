import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 4 — sincronización de escaneos del PDA.
 *
 * `scan_type=inventory` no mueve el estado del envío: solo escribe el escaneo
 * y el evento de tracking, que es justo el camino que mide el SLO. Cada
 * iteración usa un `scan_id` nuevo para no caer en la rama de duplicados.
 *
 * Criterio de la fase: p95 de POST /operations/scans/sync <= 300 ms.
 */
export const options = {
  scenarios: {
    scans: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 10),
      duration: __ENV.DURATION || '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:sync_scans}': ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const TICKET_CODE = __ENV.TICKET_CODE || '';
const SCAN_TYPE = __ENV.SCAN_TYPE || 'inventory';
const BATCH = Number(__ENV.BATCH || 1);
const DEVICE_ID = __ENV.DEVICE_ID || 'PDA-LIM-01';

function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = (Math.random() * 16) | 0;
    const value = char === 'x' ? random : (random & 0x3) | 0x8;

    return value.toString(16);
  });
}

export default function () {
  const scans = Array.from({ length: BATCH }, () => ({
    scan_id: uuid(),
    ticket_code: TICKET_CODE,
    scan_type: SCAN_TYPE,
    scanned_at: new Date().toISOString(),
  }));

  const response = http.post(
    `${BASE_URL}/api/v1/operations/scans/sync`,
    JSON.stringify({ device_id: DEVICE_ID, scans }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${TOKEN}`,
      },
      tags: { name: 'sync_scans' },
    }
  );

  check(response, {
    'scan -> 200': (r) => r.status === 200,
    'scan -> accepted': (r) => r.json('data.accepted') === BATCH,
  });
}
