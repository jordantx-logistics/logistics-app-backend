# AGENTS.md

La guía del repositorio está en **`../AGENTS.md`** (raíz) y ahí vive la única fuente de verdad: stack, comandos, arquitectura modular, fronteras entre contextos (ADR-0006), health checks, logs, métricas, tests y convenciones. Léelo antes de tocar código.

Recordatorio corto para no tropezar con lo que ya cambió:

- El backend se ejecuta **solo con Docker** (Octane sobre FrankenPHP). No hay PHP/Composer del host, así que `composer dev` y `php artisan serve` **no aplican**; usa `docker compose exec app php artisan test` o `docker compose exec app ./vendor/bin/pint`.
- El código de negocio va en `src/` (PSR-4 `Src\`), no en `app/`.
- Tras cambiar código: `docker compose exec app php artisan octane:reload`. Tras tocar `Dockerfile` o `docker/entrypoint.sh`: `docker compose build app`.
- Fronteras (ADR-0006): ningún contexto importa clases de otro; se comunica por puerto (`src/Shared/Contracts/`) o evento, y la regla cruzada vive en una Rule que recibe el puerto.
