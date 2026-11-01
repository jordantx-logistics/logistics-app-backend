import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 5 — SLO de cierre de caja.
 *
 * El SLO del roadmap agrupa pago y cierre de caja (p95 ≤ 500 ms). Este script
 * mide la consulta de la sesión y hace **un** cierre al final, con el importe
 * esperado para no dejar un descuadre pendiente de aprobación.
 *
 * Ejecútalo sin carga de pagos en paralelo: si el esperado cambia entre el
 * `setup` y el `teardown`, el backend deja el cierre pendiente (que también es
 * una respuesta correcta, pero ensucia los datos de demo).
 */
export const options = {
  scenarios: {
    current: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 2),
      duration: __ENV.DURATION || '10s',
    },
  },
  thresholds: {
    'http_req_duration{name:current_cash_session}': ['p(95)<300'],
    'http_req_duration{name:close_cash_session}': ['p(95)<500'],
    http_req_failed: ['rate<0.005'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';

function headers() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${TOKEN}`,
  };
}

function current() {
  return http.get(`${BASE_URL}/api/v1/finance/cash-sessions/current`, {
    headers: headers(),
    tags: { name: 'current_cash_session' },
  });
}

export function setup() {
  let response = current();
  let session = response.json('data.cash_session');

  if (!session) {
    http.post(
      `${BASE_URL}/api/v1/finance/cash-sessions`,
      JSON.stringify({ opening_amount: 0 }),
      { headers: headers(), tags: { name: 'open_cash_session' } },
    );

    response = current();
    session = response.json('data.cash_session');
  }

  check(response, {
    'cash session available': () => Boolean(session),
  });

  return { sessionId: session?.id, expectedAmount: session?.expected_amount };
}

export default function () {
  check(current(), {
    'current cash session -> 200': (r) => r.status === 200,
  });
}

export function teardown(data) {
  if (!data.sessionId) {
    return;
  }

  const response = current();
  const expected = response.json('data.cash_session.expected_amount') || data.expectedAmount;

  const closed = http.post(
    `${BASE_URL}/api/v1/finance/cash-sessions/${data.sessionId}/close`,
    JSON.stringify({ closing_amount: Number(expected) }),
    { headers: headers(), tags: { name: 'close_cash_session' } },
  );

  check(closed, {
    'close cash session -> 200': (r) => r.status === 200,
  });
}
