# Contribuir

Guía de trabajo del repositorio `logistics-app-backend` (Laravel 13 + PHP 8.3, monolito modular).
El diseño y las decisiones viven en `../spec/`.

## Entorno: Docker obligatorio

Este proyecto **no** se ejecuta con PHP/Composer instalados en el host. Todo pasa por Docker.

```bash
# Levantar la infraestructura local (app, horizon, scheduler, mysql, redis, nginx, mailpit)
docker compose up -d --build

# Dependencias y arranque (solo la primera vez)
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# Comandos del día a día
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint
docker compose exec app php artisan scribe:generate
docker compose logs -f app
```

Reglas:

- No instalar ni invocar PHP/Composer del host para este repositorio.
- `vendor/` y `.env` no se versionan; se recrean dentro del contenedor.
- Tras cambiar código, `docker compose exec app php artisan octane:reload` (el worker de Octane cachea el código en memoria).

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
- Rutas protegidas con `auth:sanctum` + `permission:`.
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

## Documentación

- Documentación en español; código en inglés.
- Las decisiones de arquitectura se registran como ADR en `../spec/09-decisiones/`.
