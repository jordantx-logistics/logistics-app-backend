import http from 'k6/http';
import { check } from 'k6';

export const options = {
  scenarios: {
    list: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 20),
      duration: __ENV.DURATION || '2m',
    },
  },
  thresholds: {
    'http_req_duration{name:list_orders}': ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const STATUS = __ENV.STATUS || 'pending';
const ORIGIN_AGENCY_ID = __ENV.ORIGIN_AGENCY_ID || '';

export default function () {
  let url = `${BASE_URL}/api/v1/logistics/orders?per_page=15&status=${encodeURIComponent(STATUS)}`;

  if (ORIGIN_AGENCY_ID !== '') {
    url += `&origin_agency_id=${encodeURIComponent(ORIGIN_AGENCY_ID)}`;
  }

  const response = http.get(url, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${TOKEN}`,
    },
    tags: { name: 'list_orders' },
  });

  check(response, {
    'list -> 200': (r) => r.status === 200,
  });
}
