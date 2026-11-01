// Criterio 4 de la Fase 7: exportación encolada y disponible en p95 ≤ 30 s, y
// dashboard dentro del SLO de reportes síncronos (≤ 1 s).
//
//   docker run --rm -i --network host -e BASE_URL=http://localhost:8000 \
//     -e EMAIL=... -e PASSWORD=... grafana/k6 run - < load/phase7-reports.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const exportReady = new Trend('report_export_ready_ms', true);

export const options = {
  vus: 3,
  iterations: 30,
  thresholds: {
    report_export_ready_ms: ['p(95)<30000'],
    'http_req_duration{name:kpis}': ['p(95)<1000'],
    'http_req_failed{name:request}': ['rate==0'],
  },
};

export function setup() {
  const response = http.post(
    `${BASE_URL}/api/v1/auth/token`,
    JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD, device_name: 'k6-phase7' }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } },
  );

  return { token: response.json('data.token') };
}

function day(offset) {
  return new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
}

export default function ({ token }) {
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  check(http.get(`${BASE_URL}/api/v1/dashboard/kpis`, { headers, tags: { name: 'kpis' } }), {
    'kpis 200': (r) => r.status === 200,
  });

  const started = Date.now();
  const report = ['orders', 'revenue', 'tracking', 'cash-closures', 'fleet-consumption', 'incidents'][
    __ITER % 6
  ];
  const created = http.post(
    `${BASE_URL}/api/v1/reports/exports`,
    JSON.stringify({ report, format: 'csv', from: day(-30), to: day(0) }),
    { headers, tags: { name: 'request' } },
  );

  check(created, { 'request 202': (r) => r.status === 202 });

  const id = created.json('data.id');

  for (let attempt = 0; attempt < 120; attempt++) {
    sleep(0.5);

    const status = http
      .get(`${BASE_URL}/api/v1/reports/exports/${id}`, { headers, tags: { name: 'poll' } })
      .json('data.status');

    if (status === 'ready' || status === 'failed') {
      check(status, { 'export ready': (value) => value === 'ready' });
      exportReady.add(Date.now() - started);

      return;
    }
  }

  check(null, { 'export ready': () => false });
}
