# Contribuir

Guía de trabajo del repositorio `logistics-app-backend` (Laravel 13 + PHP 8.3, monolito modular).
El diseño y las decisiones viven en `../spec/`.

## Entorno: Docker obligatorio

Este proyecto **no** se ejecuta con PHP/Composer instalados en el host. Todo pasa por Docker.

```bash
# Levantar la infraestructura local (app/Octane, scheduler, nginx, mysql, redis, mailpit)
# `horizon` es de la Fase 1 y va tras perfil: docker compose --profile horizon up -d horizon
docker compose up -d --build

# Dependencias y arranque (solo la primera vez)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# Comandos del día a día
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint
docker compose exec app php artisan scribe:generate   # la doc generada no se versiona
docker compose logs -f app
```

Reglas:

- No instalar ni invocar PHP/Composer del host para este repositorio.
- `vendor/` y `.env` no se versionan; se recrean dentro del contenedor.
- Tras cambiar código, `docker compose exec app php artisan octane:reload` (el worker de Octane cachea el código en memoria).
- Tras tocar `Dockerfile` o `docker/entrypoint.sh` hay que reconstruir: `docker compose build app` (se copian dentro de la imagen, no van montados).
- Cambiar `.env` requiere recrear el contenedor: `docker compose up -d --force-recreate app` (`octane:reload` no relee el entorno).

## Logs

La app registra **una línea JSON por evento a stderr**, con `context`/`extra` aplanados en la raíz:

```bash
docker compose logs -f app
# {"method":"GET","route":"api/v1/permission/roles","status":200,"duration_ms":114.7,
#  "ip":"172.20.0.6","request_id":"trace-auth-600","user_id":2,
#  "message":"http_request","level_name":"INFO","datetime":"..."}
```

- Cada petición lleva `request_id`: se respeta el `X-Request-Id` que envíe el cliente y se devuelve en la respuesta, así que una traza se sigue de extremo a extremo. `user_id` aparece solo si la petición va autenticada.
- `OCTANE_LOG_LEVEL` (`warn` por defecto) controla el nivel de Caddy, no el de la app (`LOG_LEVEL`). Necesario para que Octane no interprete el stderr de los workers como log de Caddy.
- En los tests `LOG_CHANNEL=null` (`phpunit.xml`), así que la suite no imprime logs.

## Métricas

`GET /metrics` sirve el scrape de Prometheus (formato texto 0.0.4):

```bash
curl -s http://localhost:8000/metrics | grep http_requests_total
# con METRICS_TOKEN definido, hay que enviarlo:
curl -s -H "Authorization: Bearer $METRICS_TOKEN" http://localhost:8000/metrics
```

- Acceso: con `METRICS_TOKEN` se exige el token; sin él solo se atiende desde red privada (dentro del clúster). `METRICS_ENABLED=false` apaga el endpoint (404).
- Series expuestas: `http_requests_total`, `http_request_duration_seconds`, `db_connections_used` (gauge leído en el scrape) y `octane_workers_busy` (peticiones en curso).
- El exporter nunca puede tumbar una petición: si el almacén falla se registra `metrics_unavailable` y la petición continúa.

## Ramas

| Rama | Uso |
|---|---|
| `main` | Estable. Solo entra código que pasó CI y revisión. |
| `develop` | Integración. Base de todo trabajo nuevo. |
| `feature/<tema>` | Nueva funcionalidad. |
| `fix/<tema>` | Corrección de errores. |
| `chore/<tema>` | Mantenimiento, tooling, documentación. |

Flujo: se crea la rama desde `develop`, se abre PR hacia `develop` con CI en verde, y `develop` promueve a `main`.
Los cambios incompatibles de API no rompen `api/v1/` en caliente: se versionan (`api/v2/`).

## Commits

[Conventional Commits](https://www.conventionalcommits.org/):

```
<tipo>(<alcance>): <descripción>
```

- Tipos: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `ci`, `perf`.
- Alcance en inglés y en minúsculas, alineado con el módulo o el área (`auth`, `orders`, `shared`, `docker`, `ci`).
- Ejemplos: `feat(orders): add order listing with filters`, `fix(auth): return neutral response on forgot-password`, `chore(docker): enable octane worker role`.
- El mensaje puede ir en español; los identificadores del código, siempre en inglés.

## Código

- Lógica de negocio en `src/<Contexto>/` (PSR-4 `Src\`); `app/` solo scaffolding.
- Toda operación de negocio es un **UseCase**; los controladores son finos.
- Validaciones de negocio en **Rules** dentro del `RulesPipeline`.
- Estado de cambio se escribe en `outbox_events` dentro de la transacción (patrón outbox).
- Respuestas siempre con `$this->success(...)` / `$this->error(...)`.
- Rutas protegidas con `auth:api` (JWT) y permisos de `spatie/laravel-permission`.
- Un contexto **no** importa modelos de otro contexto: se comunica por interfaz de dominio o evento (ADR-0006 y ADR-0001).

## Calidad antes del PR

```bash
docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan test
docker compose exec app php artisan scribe:generate   # el contrato OpenAPI es artefacto de CI
```

- Pint sin diferencias, suite Pest en verde.
- Cobertura ≥ 80 % en dominios críticos.
- Si se añade o cambia una ruta, se actualiza Scribe y se regenera el contrato.

## CI

`.github/workflows/ci.yml` corre en cada push a `develop`/`main` y en cada PR (ADR-0010). Como el backend se ejecuta solo con Docker, **todo pasa dentro de la imagen**: el runner no instala PHP.

| Paso | Qué comprueba |
|---|---|
| Construir la imagen | Que el `Dockerfile` compila (es el artefacto que se despliega). |
| Dependencias y entorno | `composer install` con el `composer.lock`, `.env` + `APP_KEY` + `JWT_SECRET` (no van versionados). |
| Validar docker-compose | `docker compose config --quiet`. |
| Estilo | `./vendor/bin/pint --test`. |
| Tests | `php artisan test` (Pest, SQLite en memoria). |
| Migraciones sobre MySQL | `php artisan migrate --seed` contra un MySQL 8.0 real, no solo SQLite. |
| Contrato | `php artisan scribe:generate` y subida del artefacto `openapi` (`storage/app/private/scribe/openapi.yaml`), base de ADR-0008. |

Para reproducir cualquier paso: es el mismo comando dentro del contenedor, por ejemplo

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
```

## Documentación

- Documentación en español; código en inglés.
- Las decisiones de arquitectura se registran como ADR en `../spec/09-decisiones/`.
