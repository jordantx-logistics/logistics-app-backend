import http from 'k6/http';
import { check } from 'k6';

/*
 * Fase 6 — criterio 7: p95 de los listados de Flota <= 300 ms.
 *
 * `TOKEN` debería ser de un usuario sin ámbito nacional cuando se quiera medir el
 * filtro más caro: `visibleTo` combina agencias de origen, destino y chofer.
 */
export const options = {
  scenarios: {
    lists: {
      executor: 'constant-vus',
      vus: Number(__ENV.VUS || 10),
      duration: __ENV.DURATION || '30s',
    },
  },
  thresholds: {
    'http_req_duration{name:list_routes}': ['p(95)<300'],
    'http_req_duration{name:list_expenses}': ['p(95)<300'],
    'http_req_duration{name:list_vehicles}': ['p(95)<300'],
    'http_req_duration{name:list_drivers}': ['p(95)<300'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const TOKEN = __ENV.TOKEN || '';
const FLEET_TOKEN = __ENV.FLEET_TOKEN || TOKEN;

const headers = (token) => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });

export default function () {
  const routes = http.get(`${BASE_URL}/api/v1/fleet/routes?per_page=15&status=assigned,in_progress`, {
    headers: headers(TOKEN),
    tags: { name: 'list_routes' },
  });
  const expenses = http.get(`${BASE_URL}/api/v1/fleet/expenses?per_page=15`, {
    headers: headers(TOKEN),
    tags: { name: 'list_expenses' },
  });
  const vehicles = http.get(`${BASE_URL}/api/v1/fleet/vehicles?per_page=15&status=available`, {
    headers: headers(FLEET_TOKEN),
    tags: { name: 'list_vehicles' },
  });
  const drivers = http.get(`${BASE_URL}/api/v1/fleet/drivers?per_page=15&available=true`, {
    headers: headers(FLEET_TOKEN),
    tags: { name: 'list_drivers' },
  });

  check(routes, { 'routes 200': (r) => r.status === 200 });
  check(expenses, { 'expenses 200': (r) => r.status === 200 });
  check(vehicles, { 'vehicles 200': (r) => r.status === 200 });
  check(drivers, { 'drivers 200': (r) => r.status === 200 });
}
