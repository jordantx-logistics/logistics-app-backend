import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 9 — carga combinada sobre el stack de escala (`load/README.md`).
 *
 * Tasas por defecto, en peticiones por segundo:
 *  - counter: 12, unas 1M de órdenes al día.
 *  - scans: 12 lotes de 20, unos 20M de eventos de tracking al día.
 *  - tracking y lists: consultas sobre la réplica.
 *  - payments: cobros de varios cajeros, cada uno con su sesión de caja.
 *
 * `FACTOR` multiplica todas las tasas para medir picos. `ONLY=counter` deja uno solo.
 */
const fixture = JSON.parse(open('./fixture.json'));
const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const FACTOR = Number(__ENV.FACTOR || 1);
const DURATION = __ENV.DURATION || '3m';

const rates = { counter: 12, scans: 12, tracking: 50, lists: 30, payments: 10 };

const only = __ENV.ONLY ? __ENV.ONLY.split(',') : Object.keys(rates);

export const options = {
  scenarios: Object.fromEntries(only.map((name) => [name, {
    executor: 'constant-arrival-rate',
    exec: name,
    rate: Math.ceil(rates[name] * FACTOR),
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: Math.ceil(rates[name] * FACTOR * 2),
    maxVUs: Math.ceil(rates[name] * FACTOR * 10),
  }])),
  thresholds: {
    'http_req_duration{name:create_order}': ['p(95)<500'],
    'http_req_duration{name:sync_scans}': ['p(95)<500'],
    'http_req_duration{name:track}': ['p(95)<300'],
    'http_req_duration{name:list_orders}': ['p(95)<300'],
    'http_req_duration{name:create_payment}': ['p(95)<500'],
    'http_req_failed{status:500}': ['rate<0.001'],
    http_req_failed: ['rate<0.001'],
  },
  summaryTrendStats: ['p(50)', 'p(95)', 'p(99)', 'max'],
};

function headers(token) {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

function site() {
  return fixture.sites[(__VU + __ITER) % fixture.sites.length];
}

function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = (Math.random() * 16) | 0;

    return (char === 'x' ? random : (random & 0x3) | 0x8).toString(16);
  });
}

function ticket(entry) {
  return entry.tickets[Math.floor(Math.random() * entry.tickets.length)];
}

function orderBody(origin, destination, weight) {
  return JSON.stringify({
    idempotency_key: uuid(),
    sender_id: fixture.sender_id,
    receiver_id: fixture.receiver_id,
    origin_agency_id: origin,
    destination_agency_id: destination,
    description: 'k6 fase 9',
    weight_kg: weight,
    volume_m3: 0.1,
    declared_value: 100,
  });
}

export function setup() {
  if (!only.includes('payments')) {
    return { orders: {} };
  }

  // Cada cajero cobra sobre su caja y una orden propia con saldo de sobra.
  const orders = {};

  fixture.sites.forEach((entry, index) => {
    const destination = fixture.sites[(index + 1) % fixture.sites.length].agency_id;
    const created = http.post(
      `${BASE_URL}/api/v1/logistics/orders`,
      orderBody(entry.agency_id, destination, 400),
      { headers: headers(entry.counter_token) },
    );

    orders[entry.agency_id] = created.json('data.id');

    http.post(
      `${BASE_URL}/api/v1/finance/cash-sessions`,
      JSON.stringify({ opening_amount: 100 }),
      { headers: headers(entry.cashier_token) },
    );
  });

  return { orders };
}

export function counter() {
  const entry = site();
  const destination = fixture.sites[(fixture.sites.indexOf(entry) + 1) % fixture.sites.length].agency_id;
  const response = http.post(
    `${BASE_URL}/api/v1/logistics/orders`,
    orderBody(entry.agency_id, destination, 5 + Math.random() * 20),
    { headers: headers(entry.counter_token), tags: { name: 'create_order' } },
  );

  check(response, { 'order -> 201': (r) => r.status === 201 });
}

export function scans() {
  const entry = site();
  const batch = Array.from({ length: 20 }, () => ({
    scan_id: uuid(),
    ticket_code: ticket(entry),
    scan_type: 'inventory',
    scanned_at: new Date().toISOString(),
  }));
  const response = http.post(
    `${BASE_URL}/api/v1/operations/scans/sync`,
    JSON.stringify({ device_id: `PDA-LOAD-${entry.agency_id}`, scans: batch }),
    { headers: headers(entry.operator_token), tags: { name: 'sync_scans' } },
  );

  check(response, { 'scans -> 200': (r) => r.status === 200 });
}

export function tracking() {
  const entry = site();
  const response = http.get(`${BASE_URL}/api/v1/operations/tracking/${ticket(entry)}`, {
    headers: headers(entry.operator_token),
    tags: { name: 'track' },
  });

  check(response, { 'track -> 200': (r) => r.status === 200 });
}

export function lists() {
  const entry = site();
  const response = http.get(`${BASE_URL}/api/v1/logistics/orders?origin_agency_id=${entry.agency_id}`, {
    headers: headers(entry.counter_token),
    tags: { name: 'list_orders' },
  });

  check(response, { 'list -> 200': (r) => r.status === 200 });
}

export function payments(data) {
  const entry = site();
  const response = http.post(
    `${BASE_URL}/api/v1/finance/payments`,
    JSON.stringify({
      order_id: data.orders[entry.agency_id],
      amount: '0.01',
      payment_method: 'cash',
      document_type: 'boleta',
      idempotency_key: uuid(),
    }),
    { headers: headers(entry.cashier_token), tags: { name: 'create_payment' } },
  );

  check(response, { 'payment -> 201': (r) => r.status === 201 });
}
