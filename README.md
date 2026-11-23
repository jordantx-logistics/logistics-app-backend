# Logistics App Backend

Laravel 13 REST API para gestión de logística (envíos, pagos, agencias y clientes).

[![PHP 8.3](https://img.shields.io/badge/PHP-8.3-blue.svg)](https://php.net/)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20.svg)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Características

- **Autenticación con Sanctum**: cookie de sesión HttpOnly para la web y tokens opacos para las apps móviles.
- **Logística**: órdenes de envío con idempotencia, tarifas, ubicaciones y pagos.
- **Operaciones**: recepción, inventario, embarques, reparto de última milla y tracking.
- **Finanzas**: cobros, caja, comprobantes electrónicos (SUNAT), notas de crédito y anulaciones.
- **Flota**: rutas, choferes, vehículos y gastos.
- **Identidad**: usuarios, roles y permisos (Spatie), empleados, asistencia y dispositivos.
- **Incidencias, reportes y ajustes** del sistema.
- **Eventos asíncronos**: patrón outbox con Horizon sobre Redis.

## Arquitectura

### Estructura Modular

Monolito modular con un contexto por carpeta en `src/`. Cada contexto expone sus rutas bajo `api/v1/...` y se comunica con los demás por contratos (`src/Shared/Contracts`) y eventos del outbox.

| Contexto | Módulos | Prefijo |
|---|---|---|
| Identity | User, Role, Employee, Attendance, Device | `api/v1/auth`, `api/v1/identity`, `api/v1/permission` |
| Organization | Agencias | `api/v1/organization` |
| Customer | Personas y empresas | `api/v1/customer` |
| Logistics | Order, Tariff, Location, Payment | `api/v1/logistics` |
| Operations | Reception, Inventory, Shipment, Delivery, Tracking | `api/v1/operations` |
| Finance | Payment, Cash, Invoice, CreditNote, Void | `api/v1/finance` |
| Fleet | Route, Driver, Vehicle, Expense | `api/v1/fleet` |
| Incident | Incidencias | `api/v1/incidents` |
| Reporting | Reportes y exportaciones | `api/v1/reports` |
| Settings | Ajustes | `api/v1/settings` |
| System | Salud, métricas y utilidades | `api/v1` |
| Shared | Contratos, outbox, pipelines, excepciones y traits | — |

### Request Flow

```
┌─────────┐     ┌──────────┐     ┌─────────┐     ┌─────────┐
│  Client │────▶│Controller│────▶│ Form    │────▶│ UseCase │
│ Request │     │ (Thin)   │     │ Request │     │         │
└─────────┘     └──────────┘     └─────────┘     └────┬────┘
                                                     │
                                                     ▼
                                            ┌─────────────────┐
                                            │  RulesPipeline  │
                                            │  (Validations)  │
                                            └────────┬────────┘
                                                     │
                                                     ▼
                                            ┌─────────────────┐
                                            │    Services     │
                                            │  (Business      │
                                            │   Logic)        │
                                            └────────┬────────┘
                                                     │
                                                     ▼
┌─────────┐     ┌───────────┐     ┌─────────┐     ┌─────────┐
│ Response│◀────│ApiResponse│◀────│ Model   │◀────│ Outbox  │
│(JSON)   │     │Trait      │     │ + DB    │     │ Event   │
└─────────┘     └───────────┘     └─────────┘     └─────────┘
```

### Patrones Clave

**1. Outbox Pattern** - Eventos asíncronos en `outbox_events` dentro de transacciones DB:
```php
DB::transaction(function () use ($order) {
    $order->save();
    OutboxEvent::create([
        'event_type' => 'order.created',
        'payload' => [...],
    ]);
});
```

**2. Ticket Identifiers** - Doble identificación única:
```php
// ticket_code: hash único (A7F3Q8X4)
// ticket_number: secuencial (ORD-2026-000001)
```

**3. Idempotency** - Previene duplicados con `idempotency_key`:
```php
CheckDuplicateOrder::class // valida idempotency_key
```

## Estructura de Archivos

```
src/
├── Identity/           # User, Role, Employee, Attendance, Device
├── Organization/       # Agencias
├── Customer/           # Personas y empresas
├── Logistics/          # Order, Tariff, Location, Payment
├── Operations/         # Reception, Inventory, Shipment, Delivery, Tracking
├── Finance/            # Payment, Cash, Invoice, CreditNote, Void
├── Fleet/              # Route, Driver, Vehicle, Expense
├── Incident/
├── Reporting/
├── Settings/
├── System/             # Salud, métricas, middleware
└── Shared/             # Contracts, DTOs, Exceptions, Jobs, Models (OutboxEvent),
                        # Pipelines, Scope, Services, Traits, ValueObjects
```

Dentro de cada módulo: `Controllers`, `Requests`, `UseCases`, `Models`, `Resources`, `Rules` y su `routes.php`.

## Instalación

```bash
# 1. Clonar repositorio
git clone <repository>
cd logistics-app-backend

# 2. Instalar dependencias
composer install
npm install

# 3. Copiar archivo .env
cp .env.example .env

# 4. Generar clave de aplicación
php artisan key:generate

# 5. Migrar base de datos
php artisan migrate

# 6. Build assets (opcional, si usas Vite)
npm run build
```

### Variables de Entorno

Asegurate de configurar las variables en `.env`:

**MySQL (Docker):**
```env
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=logistics_app
DB_USERNAME=root
DB_PASSWORD=root
```

**MySQL (Nativo):**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=logistics_app
DB_USERNAME=root
DB_PASSWORD=
```

**Redis:**
```env
REDIS_HOST=redis        # Docker
# REDIS_HOST=127.0.0.1  # Nativo
REDIS_PORT=6379
```

**Sanctum:**
```env
SANCTUM_STATEFUL_DOMAINS=localhost:5173   # Dominios de la SPA que usan cookie de sesión
SANCTUM_TOKEN_EXPIRATION=43200            # Minutos de vida de los tokens de las apps
SESSION_SECURE_COOKIE=false               # true en producción (HTTPS)
```

**Scribe (API docs):**
```env
SCRIBE_AUTH_ENABLED=true
SCRIBE_AUTH_EMAIL=admin@admin.pe
SCRIBE_AUTH_PASSWORD='TuPassword'
```

## Desarrollo

### Con Docker (Recomendado)

Levanta toda la infraestructura (PHP-FPM, Nginx, MySQL 8.0, Redis) con un solo comando.

```bash
# 1. Construir y levantar todos los servicios
docker compose up -d --build

# 2. Instalar dependencias dentro del contenedor
docker compose exec app composer install

# 3. Generar clave de aplicación
docker compose exec app php artisan key:generate

# 4. Migrar base de datos
docker compose exec app php artisan migrate --force

# 5. Sembrar datos iniciales
docker compose exec app php artisan db:seed --force
```

**Accesos:**
| Servicio | URL | Puerto |
|----------|-----|--------|
| API | `http://localhost:8000` | `:8000` (Nginx) |
| MySQL | `localhost:3306` | `:3306` |
| Redis | `localhost:6379` | `:6379` |
| Vite HMR | `http://localhost:5173` | `:5173` (node) |

**Comandos útiles:**

```bash
# Ver logs en tiempo real
docker compose logs -f app

# Ejecutar artisan dentro del contenedor
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test

# Detener servicios (mantiene volúmenes)
docker compose down

# Detener y eliminar volúmenes (datos se pierden)
docker compose down -v

# Reconstruir después de cambios en Dockerfile
docker compose up -d --build
```

**Estructura de servicios:**

| Servicio | Imagen | Función |
|----------|--------|---------|
| `app` | PHP 8.3-FPM | Motor Laravel |
| `nginx` | Nginx Alpine | Reverse proxy |
| `mysql` | MySQL 8.0 | Base de datos |
| `redis` | Redis 7 | Cache / queues |
| `node` | Node 20 Alpine | Vite dev server |

### Sin Docker (Nativo)

```bash
# Iniciar servidor de desarrollo (server + queue + Vite)
composer dev

# Accesos:
# - API Server: http://localhost:8000
# - Vite HMR: http://localhost:5173
# - Queue Worker: background
```

## Reglas de Desarrollo

### Ubicación del Código

- **Business logic**: `src/<Module>/` (PSR-4: `Src\`)
- **Scaffolding**: `app/` (User model, base Controller, providers)
- **Shared code**: `src/Shared/` (Traits, Services, Pipelines, Exceptions, Models)

### Patrón UseCase

**Controlador (thin)**:
```php
public function store(CreateOrderRequest $request, CreateOrderUseCase $useCase)
{
    $order = $useCase->execute($request->validated());
    return $this->success(OrderResource::make($order));
}
```

**UseCase (business logic)**:
```php
public function execute(array $data): Order
{
    // 1. Validaciones via Pipeline
    $data = RulesPipeline::run($data, [
        CheckDuplicateOrder::class,
        ValidateCustomerExists::class,
        ValidateAgencyExists::class,
    ]);
    
    // 2. Lógica de negocio
    $data['total_amount'] = $this->pricingService->calculate($data);
    
    // 3. Transacción + Outbox
    return DB::transaction(function () use ($data) {
        $order = Order::create([...]);
        OutboxEvent::create([...]);
        return $order;
    });
}
```

### Patrón Rules

```php
class CheckDuplicateOrder
{
    public function handle(array $payload, Closure $next): mixed
    {
        if (Order::where('idempotency_key', $payload['idempotency_key'])->exists()) {
            throw new BusinessRuleException(
                message: 'Order already exists',
                errorCode: 'DUPLICATE_ORDER',
                statusCode: 409
            );
        }
        return $next($payload);
    }
}
```

### API Responses

```php
// Éxito
return $this->success(data: $data, message: 'Success', statusCode: 200);

// Error
return $this->error(message: 'Error', data: $errors, statusCode: 400);

// Response shape: { "success": true/false, "message": "...", "data": {...} }
```

### Testing

- **DB**: SQLite `:memory:` (phpunit.xml)
- **Queue**: `sync` (no queues en tests)
- **BCRYPT_ROUNDS**: 4 (no benchmarking)
- **Fixtures**: Factories en `database/factories/`

```bash
# Ejecutar tests
composer test

# Test individual
./vendor/bin/pest tests/Feature/OrderTest.php

# Test por nombre
./vendor/bin/pest --filter='it creates an order'
```

## Agregar Nuevo Módulo

```bash
# Generar estructura
php artisan make:module Logistics/Shipment

# Crea:
# src/Logistics/Shipment/{Controllers,Models,Requests,Rules,Services,UseCases}
# src/Logistics/Shipment/routes.php
```

`ModuleServiceProvider` descubre automáticamente los `routes.php` de `src/*/routes.php` y `src/*/*/routes.php`, así que no hay que registrar nada: en cuanto el módulo declara sus rutas bajo `api/…`, están accesibles.

## Agregar Nueva Ruta

Añade las rutas al `routes.php` del módulo que corresponda:

```php
// src/Logistics/Shipment/routes.php
Route::prefix('api/v1/logistics')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::apiResource('shipments', ShipmentController::class);
    });
```

El prefijo de primer nivel (`api/…`) es el que identifica al contexto; `tests/Feature/Architecture/ModuleRoutesTest.php` falla si un `routes.php` declara un prefijo que no acaba registrado en el router.

## Base de Datos

### Tablas Principales

| Tabla | Descripción | Campos Clave |
|-------|-------------|--------------|
| `orders` | Envíos | ticket_number, ticket_code, idempotency_key, sender_id, receiver_id, origin/destination_agency_id, weight_kg, total_amount, status |
| `payments` | Pagos | ticket_number, order_id, amount, payment_method, status |
| `persons` | Clientes naturales | document_type, document_number, first_name, last_name |
| `companies` | Empresas | tax_id (RUC), business_name |
| `agencies` | Agencias | code, name, address, is_active |
| `outbox_events` | Eventos asíncronos | event_type, payload |
| `sequences` | Secuenciadores | type (ticket_number), current_value |

### Diagrama ERD (Simplificado)

```
┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐
│     persons     │      │    companies    │      │    agencies     │
├─────────────────┤      ├─────────────────┤      ├─────────────────┤
│ id (PK)         │      │ id (PK)         │      │ id (PK)         │
│ document_type   │      │ tax_id (PK)     │      │ code            │
│ document_number │      │ business_name   │      │ name            │
│ first_name      │      │ address         │      │ is_active       │
│ last_name       │      └─────────────────┘      └─────────────────┘
│ email           │              │                    │
│ phone           │              │                    │
└────────┬────────┘              │                    │
         │                       │                    │
         └───────────────────────┼────────────────────┘
                                │
                                │
                    ┌───────────┴───────────┐
                    │                       │
                    ▼                       ▼
         ┌───────────────────┐      ┌─────────────────┐
         │    orders         │      │    payments     │
         ├───────────────────┤      ├─────────────────┤
         │ id (PK)           │      │ id (PK)         │
         │ ticket_number     │◀─────│ order_id (FK)   │
         │ ticket_code       │      │ amount          │
         │ idempotency_key   │      │ payment_method  │
         │ sender_id (FK)    │      │ status          │
         │ receiver_id (FK)  │      │ processed_by    │
         │ origin_agency     │      └─────────────────┘
         │ destination_agency│
         │ weight_kg         │
         │ total_amount      │
         │ status            │
         │ created_by (FK)   │
         └───────────────────┘
                    │
                    ▼
         ┌─────────────────┐
         │  outbox_events  │
         │ event_type      │
         │ payload         │
         └─────────────────┘
```

## API Reference

### Base URL

```
http://localhost:8000/api/v1/
```

### Autenticación

Laravel Sanctum con dos modos:

- **Web (SPA):** cookie de sesión HttpOnly. Antes del primer `POST` se pide `GET /sanctum/csrf-cookie`.
- **Apps:** token opaco que entrega `POST /auth/token`, en el header:

```
Authorization: Bearer {token}
```

### Endpoints

Hay más de 140 rutas. La referencia completa es el contrato OpenAPI versionado en `openapi/openapi.yaml`, que también se publica en `/docs` (Scribe). Los prefijos por contexto están en [Estructura Modular](#estructura-modular).

| Método | Endpoint | Descripción | Auth |
|--------|----------|-------------|------|
| GET | `/sanctum/csrf-cookie` | Cookie CSRF para la web | No |
| POST | `/api/v1/auth/login` | Login de la web (sesión) | No |
| POST | `/api/v1/auth/token` | Token para las apps | No |
| GET | `/api/v1/auth/me` | Usuario, roles y permisos | Sí |
| POST | `/api/v1/auth/logout` | Cerrar sesión | Sí |

### Response Format

```json
{
  "success": true,
  "message": "Order created successfully.",
  "data": {
    "id": 1,
    "ticket_number": "ORD-2026-000001",
    "ticket_code": "A7F3Q8X4",
    "status": "pending",
    "total_amount": 150.50
  }
}
```

## Mantenimiento

### Comandos

```bash
# Development
composer dev                    # serve + queue + Vite (nativo)
docker compose up -d            # levantar con Docker

# Docker
docker compose exec app php artisan test   # tests en contenedor
docker compose exec app php artisan migrate:fresh --seed  # migrar
docker compose logs -f app                 # ver logs

# Testing
composer test                   # clear config + run tests
./vendor/bin/pest --filter='...' # single test

# Code quality
./vendor/bin/pint               # PHP formatting

# Database
php artisan migrate             # migrations
php artisan migrate:fresh --seed # fresh + seed

# API docs
php artisan scribe:generate     # rebuild from docblocks
```

### API Documentation

- **URL**: `/docs`
- **Format**: OpenAPI / Postman collection
- **Auth**: Configurado en `.env` (SCRIBE_AUTH_EMAIL, SCRIBE_AUTH_PASSWORD)

### Common Issues

**Tests fallan con sequences:**
```bash
# Usar DB real (no SQLite :memory:) para tests de secuenciadores
php artisan test --filter=OrderTest --database=mysql
```

**Error: config/permission.php not loaded:**
```bash
php artisan config:clear
php artisan vendor:publish --tag=laravel-assets
```

## Contributing

1. Fork el repositorio
2. Crear branch: `git checkout -b feature/nueva-feature`
3. Commit changes: `git commit -m 'Add nueva feature'`
4. Push: `git push origin feature/nueva-feature`
5. Abrir Pull Request

## Licencia

Este proyecto está licenciado bajo la Licencia MIT - ver el archivo [LICENSE](LICENSE) para detalles.
