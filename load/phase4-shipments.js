import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 4 — listado operativo de envíos.
 *
 * No es un SLO explícito del roadmap, pero es la consulta que más usa la web
 * de operaciones; se mide con el mismo umbral que el historial para detectar
 * regresiones de los índices de `shipments`.
 */
export const options = {
  scenarios: {
    shipments: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:list_shipments}': ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const STATUS = __ENV.STATUS || '';
const AGENCY = __ENV.AGENCY || '';
const PER_PAGE = __ENV.PER_PAGE || '15';

export default function () {
  let url = `${BASE_URL}/api/v1/operations/shipments?per_page=${encodeURIComponent(PER_PAGE)}`;

  if (STATUS !== '') {
    url += `&status=${encodeURIComponent(STATUS)}`;
  }

  if (AGENCY !== '') {
    url += `&agency=${encodeURIComponent(AGENCY)}`;
  }

  const response = http.get(url, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${TOKEN}`,
    },
    tags: { name: 'list_shipments' },
  });

  check(response, {
    'shipments -> 200': (r) => r.status === 200,
  });
}
