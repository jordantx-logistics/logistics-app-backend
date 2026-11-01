import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    create: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:create_order}': ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const SENDER_ID = Number(__ENV.SENDER_ID || 1);
const RECEIVER_ID = Number(__ENV.RECEIVER_ID || 2);
const ORIGIN_AGENCY_ID = Number(__ENV.ORIGIN_AGENCY_ID || 1);
const DESTINATION_AGENCY_ID = Number(__ENV.DESTINATION_AGENCY_ID || 2);

export function headers() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${TOKEN}`,
  };
}

export default function () {
  const idempotencyKey = `${__VU}-${__ITER}-${Date.now()}-${Math.random().toString(36).slice(2)}`;

  const payload = JSON.stringify({
    idempotency_key: idempotencyKey,
    sender_id: SENDER_ID,
    receiver_id: RECEIVER_ID,
    origin_agency_id: ORIGIN_AGENCY_ID,
    destination_agency_id: DESTINATION_AGENCY_ID,
    description: 'k6 load test',
    weight_kg: 5 + Math.random() * 20,
    volume_m3: 0.01 + Math.random() * 0.2,
    declared_value: 100,
  });

  const response = http.post(`${BASE_URL}/api/v1/logistics/orders`, payload, {
    headers: headers(),
    tags: { name: 'create_order' },
  });

  check(response, {
    'create -> 201': (r) => r.status === 201,
  });
}
