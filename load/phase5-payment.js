import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 5 — SLO de registro de cobro.
 *
 * `POST /finance/payments` debe responder p95 ≤ 500 ms: el comprobante se
 * reserva de forma síncrona, pero el XML/PDF y el envío a SUNAT quedan en cola.
 *
 * Requisitos:
 * - una sesión de caja abierta para el usuario del token;
 * - una orden con saldo suficiente (`ORDER_ID`);
 * - una serie fiscal activa para la agencia.
 *
 * El importe por defecto es 0.01 para que una sola orden soporte muchas
 * iteraciones; cada petición usa una `idempotency_key` distinta.
 */
export const options = {
  scenarios: {
    payments: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 2),
      duration: __ENV.DURATION || '15s',
    },
  },
  thresholds: {
    'http_req_duration{name:create_payment}': ['p(95)<500'],
    http_req_failed: ['rate<0.005'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const ORDER_ID = Number(__ENV.ORDER_ID || 0);
const AMOUNT = __ENV.AMOUNT || '0.01';
const PAYMENT_METHOD = __ENV.PAYMENT_METHOD || 'cash';
const DOCUMENT_TYPE = __ENV.DOCUMENT_TYPE || 'boleta';

export default function () {
  const idempotencyKey = [
    'k6',
    __VU,
    __ITER,
    Date.now(),
    Math.random().toString(36).slice(2),
  ].join('-');

  const payload = JSON.stringify({
    order_id: ORDER_ID,
    amount: AMOUNT,
    payment_method: PAYMENT_METHOD,
    document_type: DOCUMENT_TYPE,
    idempotency_key: idempotencyKey,
  });

  const response = http.post(`${BASE_URL}/api/v1/finance/payments`, payload, {
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${TOKEN}`,
    },
    tags: { name: 'create_payment' },
  });

  check(response, {
    'create payment -> 201': (r) => r.status === 201,
  });
}
