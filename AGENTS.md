# AGENTS.md

This file provides guidance to agents when working with code in this repository.

## Stack

Laravel 13 + PHP 8.3 REST API. Auth con `laravel/sanctum` (cookie de sesión para la SPA, tokens opacos para apps/PDA), `spatie/laravel-permission`, Pest 4 for tests, Scribe for API docs, Vite + Tailwind v4 (assets only — no SPA). MySQL in development, SQLite `:memory:` in tests.

## Commands

El backend se ejecuta **solo con Docker** (Octane sobre FrankenPHP). No uses PHP/Composer del host.
Ver `CONTRIBUTING.md` y `docker-compose.yml`.

- `docker compose up -d --build` — levanta app (Octane), scheduler, nginx, mysql, redis y mailpit. El worker de colas (`horizon`, Fase 1) va tras su propio perfil: `docker compose --profile horizon up -d horizon` (panel en `http://localhost:8000/horizon`).
- `docker compose exec app composer install` — instala dependencias (solo la primera vez; `vendor/` y `.env` no se versionan).
- `docker compose exec app php artisan key:generate` — genera `APP_KEY` (solo la primera vez).
- `docker compose exec app php artisan test` — suite Pest. Con filtro: `... php artisan test --filter=OrderTest` o `... php artisan test tests/Feature/OrderTest.php`.
- `docker compose exec app ./vendor/bin/pint` — formatea PHP (Laravel Pint).
- `docker compose exec app php artisan migrate` / `php artisan migrate:fresh --seed` — el contenedor tiene que estar en la red de compose (los servicios se llaman `mysql` y `redis` en `.env`), no vale un `docker run` suelto: la migración de `spatie/laravel-permission` vacía su caché de permisos, así que `migrate` necesita el almacén de caché vivo (Redis en local/prod; `array` en tests, que es lo que fija `phpunit.xml`).
- `docker compose exec app php artisan make:module Logistics/Shipment` — generador propio (ver `app/Console/Commands/MakeModuleCommand.php`); crea `src/<Name>/{Adapters,Controllers,Events,Exceptions,Jobs,Mail,Models,Requests,Resources,Rules,Services,UseCases}` + `routes.php`. No hace falta registrar nada más: `ModuleServiceProvider` descubre los `routes.php` y los comandos de `Console/Commands` automáticamente.
- `docker compose exec app php artisan scribe:generate` — regenera la documentación de API.
- `docker compose exec app php artisan permission:cache-reset` — vacía la caché de roles/permisos de Spatie (Redis). Necesario si se tocan las tablas de permisos a mano.
- `docker compose exec app php artisan octane:reload` — recarga los workers tras cambiar código. Vale igual para `scheduler` y `horizon`: también aguantan la aplicación en memoria, así que un comando o un job nuevos no existen hasta reiniciarlos. Los workers leen **toda** la configuración al arrancar: un `config/*.php` nuevo (p. ej. `cors.php`) tampoco existe hasta recargar — con el worker viejo el CORS seguía con los valores por defecto del paquete (`Access-Control-Allow-Origin: *`, sin credenciales) y el navegador rechazaba el login de la SPA aunque los tests pasaran.
- `docker compose exec scheduler php artisan outbox:consume` — publica en las colas los eventos pendientes del outbox (lo agenda el rol `scheduler` cada 5 s; a mano sirve para depurar, con `--limit=N`).
- `docker compose exec scheduler php artisan metrics:collect` — recalcula los gauges de cola y outbox (agendado cada 15 s).
- `docker compose logs -f app` — logs JSON del servicio web.
- `docker compose build app` — obligatorio tras tocar `Dockerfile` o `docker/entrypoint.sh` (se copian en la imagen, no están montados).
- CI (`.github/workflows/ci.yml`) hace lo mismo **dentro de la imagen** y con el **mismo `docker-compose.yml`** que el entorno local: `pint --test`, `php artisan test` (sin red, `--network none`), `migrate --seed` sobre MySQL 8 y `scribe:generate` (el OpenAPI se publica como artefacto `openapi`). El CI construye su imagen con `APP_IMAGE`, que es el tag que compose usa para los servicios `app`/`horizon`/`scheduler`.

Los assets (Vite) aún no se compilan dentro del contenedor; se resolverá en una fase posterior.

## Architecture

### Modular monolith under `src/`

Business code lives in **`src/`** (PSR-4 `Src\\`), NOT `app/`. `app/` only holds framework scaffolding (`User` model, base `Controller`, service providers, console commands). Each module is self-contained:

```
src/<Module>/
  Adapters/  Console/Commands/  Controllers/  Events/  Exceptions/
  Jobs/  Mail/  Models/  Requests/  Resources/  Rules/  Services/  UseCases/
  routes.php
```

Las carpetas de capa —`Models`, `UseCases`, `Jobs`, `Console`…— son las que cierran el nombre del contexto; la lista vive en `tests/Feature/Architecture/ContextBoundariesTest.php`. `ModuleServiceProvider` descubre los `routes.php` y los comandos de `src/*/Console/Commands` y `src/*/*/Console/Commands` sin registro manual.

Current modules: `Auth`, `Customer` (Persons/Companies), `Organization` (Agencies), `Logistics/Order`, `Logistics/Payment`, `System` (health checks), plus cross-cutting `Shared/`.

### Cross-context boundaries (ADR-0006)

A **context** is each `src/<Contexto>` and `src/<Contexto>/<Subcontexto>` (the folder of layers closes the name: `Logistics/Order/Models/Order.php` belongs to `Logistics\Order`). Rules:

- **No context imports another context's classes** (not just models). Communication goes through a **port** or an event. `Src\Shared\` (kernel) is the only namespace every context may use.
- Ports live in `src/Shared/Contracts/` (e.g. `CustomerDirectory`, `AgencyDirectory`); the **adapter** that knows the foreign model lives in the owning context (`src/Customer/Adapters/`, `src/Organization/Adapters/`); the **binding** lives only in the composition root `app/Providers/DomainServiceProvider.php`.
- **No foreign keys between contexts**: cross-context references are plain indexed columns (`orders.sender_id`, `payments.order_id`) and their existence is validated in a Rule through a port.
- `tests/Feature/Architecture/ContextBoundariesTest.php` enforces the imports and the missing FKs; `ModuleRoutesTest.php` enforces route discovery.

### Routing

`routes/web.php` only serves the welcome view. **All API routes are loaded by `App\Providers\ModuleServiceProvider`** (`bootstrap/providers.php`), which discovers every `src/*/routes.php` and `src/*/*/routes.php` with `glob()` (sorted for a stable order). A new module with a `routes.php` is reachable with no further registration; `tests/Feature/Architecture/ModuleRoutesTest.php` fails if a context's `api/…` prefix ends up unregistered.

All API routes live under `api/v1/<domain>/…`. Cada ruta de negocio declara `auth:sanctum` **y** `permission:<recurso.accion>`; los endpoints de `api/v1/auth/*` son los únicos fuera de ese par (rate limited con `throttle:auth`). `tests/Feature/Architecture/AuthorizationRoutesTest.php` falla si aparece una ruta de negocio sin permiso del catálogo.

### Auth y autorización (Sanctum + Spatie)

Autenticación con **Sanctum**: la SPA usa la cookie de sesión (Sanctum *stateful*, `SANCTUM_STATEFUL_DOMAINS`) y las apps/PDA un **token opaco** (`Bearer`, `SANCTUM_TOKEN_EXPIRATION` minutos). No hay JWT: `php-open-source-saver/jwt-auth`, el guard `api` y `config/jwt.php` se retiraron en la Fase 1.

- Guards (`config/auth.php`): `web` (session, la SPA) y `sanctum` (bearer). Endpoints en `src/Auth/routes.php`: `login`, `token`, `logout`, `logout-all`, `me`, `forgot-password`, `reset-password`. **No hay registro público**: el alta de usuarios es de Identity (Fase 2). Todos pasan por el rate limiter `auth` (10/min por IP y por `email|IP`, definido en `AppServiceProvider`).
- Cliente SPA: antes del primer `POST` hay que pedir `GET /sanctum/csrf-cookie` y mandar la cookie `XSRF-TOKEN` en `X-XSRF-TOKEN` (el frontend lo hace en el interceptor del cliente y reintenta una vez tras un `419`). El CORS es **con credenciales** y solo admite el origen de `FRONTEND_URL` (`config/cors.php`): un `Access-Control-Allow-Origin: *` haría que el navegador descartase la respuesta.
- **Los permisos viven en el guard `web`** y el modelo lo fija con `protected $guard_name = 'web'` (`App\Models\User` y `Src\Permission\Models\{Role,Permission}`). Es obligatorio: `Illuminate\Auth\Middleware\Authenticate` hace `shouldUse('sanctum')` al validar `auth:sanctum`, y eso **reescribe `config('auth.defaults.guard')`**; Spatie deduce de esa config el guard con el que busca el permiso, así que sin fijarlo dentro de una petición buscaría permisos de guard `sanctum` (no existen) y devolvería 403 a todo el mundo, admin incluido. Por el mismo motivo `config/permission.php` apunta a los modelos de `Src\Permission` y no a los del paquete: son los únicos con el guard fijado.
- El alias `permission:` es `Src\System\Middleware\EnforcePermission`, que envuelve el middleware de Spatie para poder leer `authz.enforce` (`config/authz.php`) **en cada petición**: con `AUTHZ_ENFORCE=false` (interruptor de rollback de la Fase 1) la ruta deja pasar sin comprobar y `AppServiceProvider` lo registra en el log al arrancar.
- Catálogo: `database/seeders/PermissionSeeder.php` (12 permisos `recurso.accion` y los 10 roles del spec; los roles cuyos módulos aún no existen quedan sin permisos). Los `permission:` de los `routes.php` solo pueden usar esos nombres; `AuthorizationRoutesTest` lo comprueba ruta a ruta.
- Ámbito por agencia: pivot `agency_user` + `User::agencyIds()` + `Agency::scopeForAgencies()`; los roles nacionales (`admin`, `analyst`, vía `User::hasNationalScope()`) ven todo y el resto solo sus agencias.
- `LogHttpRequest` lee `$request->user()` **después** de `$next`, con `auth:sanctum` ya resuelto, para el `user_id` del log.

### Request flow

Controller → FormRequest (validation) → **UseCase** (orchestration) → **RulesPipeline** → Services → Model + **OutboxEvent**.

- **UseCases** (`src/*/UseCases/*UseCase.php`) are the single entry point for business operations. Controllers are thin: validate, delegate, respond.
- **Rules** are pipeline stages with `handle($payload, Closure $next)` run via `Src\Shared\Pipelines\RulesPipeline::run($data, [RuleA::class, RuleB::class])`. They throw `BusinessRuleException` on failure (see `CreateOrderUseCase` for the canonical pattern). Rules are resolved from the container, so a rule that needs another context takes its **port** in the constructor (see `ValidateCustomerExists`).
- **Outbox pattern**: state-changing UseCases write to `outbox_events` inside the same DB transaction as the domain row. Do not dispatch jobs/events directly from UseCases — write an `OutboxEvent` row and let the consumer publish it (ver «Outbox (asincronía)»).
- **Ticket identifiers**: models using `Src\Shared\Traits\HasTicketIdentifiers` auto-populate `ticket_code` and `ticket_number` on `creating`. The model must implement `getTicketPrefix(): string` (e.g. `'ORD'`). Numbers come from the `sequences` table under a `lockForUpdate()`, so tests must use a real DB connection (SQLite `:memory:` is fine).

### API responses

Every controller extends `App\Http\Controllers\Controller`, which uses `Src\Shared\Traits\ApiResponse`. Always return via `$this->success(...)` / `$this->error(...)`. Response shape is `{ success, message, data? }`.

Exception rendering is centralized in `bootstrap/app.php` for any request matching `api/*`: `ValidationException`, `AuthenticationException`, `NotFoundHttpException`, and a catch-all that toggles debug details by `APP_DEBUG`. `BusinessRuleException` renders itself (with `error_code`) — do not add another handler for it.

### Health checks

Two endpoints, both public (a probe carries no token):

- `GET /up` — **liveness**. Laravel's built-in route, no external dependency. It is the `healthcheck` of the `app` service in `docker-compose.yml`.
- `GET /api/v1/health` — **readiness**. `src/System` checks MySQL (trivial query), Redis (`PING`) and the queue connection; returns `200` with `data.status=ok` or `503` with `data.status=degraded` plus the per-dependency detail (`status`, `detail`, `latency_ms`). Each probe is timed and never throws, so one dead dependency still reports the others.

### Logs y request_id

El canal por defecto es `json` (`config/logging.php`): una línea JSON por registro a **stderr**, con `context`/`extra` aplanados en la raíz, de modo que `request_id`, `user_id`, `route`, `status` o `duration_ms` se indexan sin abrir subobjetos.

- `AssignRequestId` (global) respeta el `X-Request-Id` del cliente —solo ids que casan `[A-Za-z0-9._-]{1,64}`, para no inyectar saltos de línea— o genera un UUID, lo guarda en `Context` y lo devuelve en la respuesta. `LogHttpRequest` (global) añade `user_id` y deja una línea `http_request` por petición.
- `Context` es el almacén de estado de petición de Laravel (`scoped`): Octane lo descarta al terminar cada petición y `ContextLogProcessor` —que `LogManager` empuja a todos los canales— vuelca sus datos en cada registro. Ese processor resuelve la aplicación **en cada llamada**; uno que capture el contenedor en su constructor se quedaría con la app original y registraría contexto vacío, porque Octane clona la aplicación en cada petición.
- Las líneas van a stderr y el *entrypoint* arranca Octane con `--log-level` (`OCTANE_LOG_LEVEL`, por defecto `warn`): sin ese flag Octane parsea el stderr de los workers como log JSON de Caddy y las líneas de la app acaban como `ERROR unknown error`.
- Los tests fijan `LOG_CHANNEL=null` (`phpunit.xml`) para no ensuciar la salida.

### Métricas

`GET /metrics` sirve el scrape de Prometheus (`promphp/prometheus_client_php`), fuera de `api/v1` porque lo consume Prometheus, no el frontend.

- `RecordHttpMetrics` (global) alimenta `http_requests_total{route,method,status,env}` y `http_request_duration_seconds{route,env}` usando el **patrón** de ruta (`api/v1/orders/{order}`) o `unmatched`: la URL nunca es etiqueta, o cada id abriría una serie temporal.
- `db_connections_used{pool,env}` se lee en cada scrape (`Threads_connected` de MySQL; `null` en SQLite) y `octane_workers_busy{env}` es una aproximación: peticiones en curso, que se descuadra si un worker muere a mitad (ver `MetricsRecorder`).
- `queue_backlog{queue,env}`, `queue_failed_total{queue,env}`, `outbox_pending_total{env}` y `outbox_pending_age_seconds{env}` las calcula `metrics:collect` desde el rol `scheduler` cada 15 s (leerlas por petición costaría una consulta por scrape en cada worker): el tamaño de cada cola se le pregunta al driver (`Queue::size`, así el nombre de la clave de Redis es cosa del driver), los fallos se cuentan como contador monótono desde `failed_jobs` (solo las filas nuevas, con el último id visto en caché) y el backlog del outbox sale de `outbox_events`. Un gauge que no se puede leer se omite; nunca tumba el scrape.
- El almacén es Redis (`METRICS_STORAGE`) para que el contador sea común a todos los workers de Octane; en tests es `in-memory`. `CollectorRegistry` está en `config/octane.php` → `warm` para no abrir una conexión de Redis por petición.
- Acceso: con `METRICS_TOKEN` hay que enviarlo (`Authorization: Bearer <token>` o `?token=`); sin token solo se atiende desde red privada/loopback. `METRICS_ENABLED=false` deja el endpoint en 404 y el exporter apagado.
- El exporter nunca puede cambiar una respuesta: si el almacén falla se registra `metrics_unavailable` y la petición sigue.

### Colas (Horizon)

Horizon (Fase 1) gestiona los workers sobre **Redis**. El worker es el servicio `horizon` (perfil homónimo) y el panel lo sirve el rol **web**, así que se ve en `http://localhost:8000/horizon`:

- `docker compose --profile horizon up -d horizon` — arranca el worker. Sin él los eventos que escriben los UseCases se quedan en Redis, en cola.
- `docker compose exec horizon php artisan horizon:status` — `0` corriendo, `1` pausado, `2` inactivo. Es el `healthcheck` del servicio: un Horizon pausado aparece como `unhealthy`, porque no procesa.
- Colas `high`, `default`, `reports`, `sync` con `balance: auto` (`config/horizon.php`), con juego distinto por entorno: local las cuatro en un supervisor, staging `high`/`default`, producción las cuatro con más procesos.
- `routes/console.php` agenda `horizon:snapshot` cada 5 minutos —el paquete registra el comando pero **no** lo programa—; lo ejecuta el rol `scheduler` y alimenta las gráficas del panel (más adelante, los gauges de cola de Prometheus).
- Acceso: en local entra cualquiera; fuera de local, solo los emails de `HORIZON_ALLOWED_EMAILS` (vacía = nadie). En staging/producción la barrera de verdad es el borde (Cloudflare Access) y el panel no se publica. La gate vive en `app/Providers/HorizonServiceProvider.php` y `tests/Feature/System/HorizonTest.php` la fija.
- Al instalar o actualizar un paquete hace falta `php artisan octane:reload`: los workers de Octane llevan la aplicación en memoria y las rutas nuevas (por ejemplo `/horizon`) no existen hasta recargar.

### Outbox (asincronía)

Los UseCases escriben un `OutboxEvent` en la misma transacción que el cambio de dominio; nadie despacha jobs a mano (`spec/02-objetivo/05-asincronia-y-eventos.md`).

- `outbox:consume` (`src/Shared/Console/Commands/ConsumeOutboxCommand.php`) lo agenda el rol `scheduler` cada 5 s —el SLO es `pending` → `processed` con p95 ≤ 5 s—. Reclama el lote con `lockForUpdate` (dos consumidores no publican el mismo evento), lo pasa a `processing` y lo marca `processed` al despacharlo. Si el despacho falla reintenta con backoff exponencial (`config/outbox.php`) y, agotados los intentos, queda `failed` para inspección manual.
- Un evento atascado en `processing` (el consumidor murió a mitad) vuelve a `pending` pasados `outbox.processing_timeout` segundos. Un `event_type` que nadie consume va directo a `failed`: es un hueco de configuración, reintentarlo no arregla nada.
- El mapa `event_type` → jobs se compone en `app/Providers/DomainServiceProvider.php` (`Src\Shared\Services\OutboxEventMap`), porque `Shared` no importa contextos (ADR-0006); cada job decide su cola con `onQueue()`.
- Idempotencia: `ProcessedEvents::once($outboxEventId, self::class, ...)` ejecuta el efecto y anota `(evento, handler)` en `processed_events` dentro de la misma transacción, y el índice único cubre la carrera de dos workers. El outbox es *at-least-once*: el evento se marca `processed` al **publicarse**, no al ejecutarse el job.
- Jobs de referencia: `GenerateOrderGuidePdfJob` y `SendOrderConfirmationMailJob` en `default`, `ProjectOrderCreatedJob` en `sync` (placeholder de la Fase 4). El de la guía guarda por ahora un borrador HTML en `storage/app/private/orders/<ticket_code>/`: el documento definitivo (y el motor PDF) es de la Fase 3.

### API docs

Scribe generates from controller docblocks (`@group`, `@apiResource`, `@apiResourceModel`, `@apiResourceAdditional`). Config in `config/scribe.php`; output in `.scribe/`.

La documentación generada (`.scribe/`, `resources/views/scribe/`, `public/vendor/scribe/`) **no se versiona**: se regenera con `docker compose exec app php artisan scribe:generate` y el contrato OpenAPI viaja como artefacto de CI. Los ejemplos de respuesta los produce Faker, así que versionarlos solo generaba ruido en cada diff. Consecuencia: `/docs` da error hasta que se ejecute `scribe:generate` una vez en el entorno.

## Testing

- Feature tests auto-use `RefreshDatabase` desde `tests/TestCase.php` (así aplica también a las clases de test con estilo PHPUnit, ya que los traits registrados en `tests/Pest.php` no llegan a esas clases).
- Helpers de `tests/Pest.php`: `adminUser()` siembra el catálogo y devuelve un usuario con el rol `admin` (todas las rutas de negocio llevan `permission:`, así que un usuario pelado recibiría 403) y `tokenHeadersFor($user)` da la cabecera `Authorization` de Sanctum. Para ejercitar un rol concreto: `adminUser()` + `removeRole('admin')` + `assignRole('counter')`.
- Auth en tests: `actingAs($user)` (guard `web`) lo recoge Sanctum; para tokens, `$user->createToken('test')->plainTextToken` + `withToken(...)`. El login por cookie solo arranca sesión si la petición manda un `Origin` que esté en `SANCTUM_STATEFUL_DOMAINS` (`localhost` en `phpunit.xml`).
- Cuidado al encadenar peticiones autenticadas en un mismo test: el guard `sanctum` es un `RequestGuard` y **cachea el usuario** entre peticiones. Usa `$this->app['auth']->forgetGuards()` antes de la siguiente si esperas otro usuario (o ninguno).
- `phpunit.xml` pins `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` and `QUEUE_CONNECTION=sync` — don't override these in individual tests.
- La suite es **hermética**: corre sin servicios (SQLite `:memory:`, caché/sesión `array`, colas `sync`) y el CI la ejecuta con `--network none`. Un test no puede depender de MySQL/Redis: mockea la dependencia (ver `HealthTest` con `Redis::shouldReceive`) o usa una ruta que no toque nada (`/up`), nunca `/api/v1/health`, que comprueba dependencias de verdad.
- `BCRYPT_ROUNDS=4` in tests — don't benchmark hashing.

## Conventions

- New business logic goes in a UseCase, not a controller or model. If validation is more than shape-checking (e.g. "customer exists", "no duplicates"), make it a Rule and put it in the pipeline.
- Shared cross-module code goes in `src/Shared/` (Traits, Services, Pipelines, Exceptions, Contracts, Models like `OutboxEvent`).
- `App\Models\User` stays in `app/` because Laravel's auth config references it; module-owned models live under `src/<Module>/Models/`.
