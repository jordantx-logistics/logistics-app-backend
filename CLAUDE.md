# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Laravel 13 + PHP 8.3 REST API. JWT auth (`php-open-source-saver/jwt-auth`), `spatie/laravel-permission`, Pest 4 for tests, Scribe for API docs, Vite + Tailwind v4 (assets only — no SPA). MySQL in development, SQLite `:memory:` in tests.

## Commands

- `composer dev` — starts `php artisan serve`, queue listener, and Vite concurrently.
- `composer test` — clears config and runs `php artisan test` (Pest). Appends filter args, e.g. `composer test -- --filter=OrderTest`.
- `php artisan test tests/Feature/OrderTest.php` — single file.
- `./vendor/bin/pest --filter='it creates an order'` — single test by name.
- `./vendor/bin/pint` — format PHP (Laravel Pint).
- `php artisan migrate` / `php artisan migrate:fresh --seed`.
- `php artisan jwt:secret` — required on first setup after `key:generate`.
- `php artisan make:module Logistics/Shipment` — custom generator (see `app/Console/Commands/MakeModuleCommand.php`); scaffolds `src/<Name>/{Controllers,Models,Requests,Resources,Rules,Services,UseCases}` + `routes.php`; the routes file needs no registration because `ModuleServiceProvider` discovers `src/*/routes.php` and `src/*/*/routes.php`.
- `php artisan scribe:generate` — rebuild API docs from controller docblocks.

## Architecture

### Modular monolith under `src/`

Business code lives in **`src/`** (PSR-4 `Src\\`), NOT `app/`. `app/` only holds framework scaffolding (`User` model, base `Controller`, service providers, console commands). Each module is self-contained:

```
src/<Module>/
  Controllers/  Models/  Requests/  Resources/  Rules/  Services/  UseCases/
  routes.php
```

Current modules: `Auth`, `Customer` (Persons/Companies), `Organization` (Agencies), `Logistics/Order`, `Logistics/Payment`, plus cross-cutting `Shared/`.

### Routing

`routes/web.php` only serves the welcome view. **All API routes are loaded by `App\Providers\ModuleServiceProvider`** (`bootstrap/providers.php`), which discovers every `src/*/routes.php` and `src/*/*/routes.php` with `glob()` (sorted for a stable order). A new module with a `routes.php` is reachable with no further registration.

All API routes live under `api/v1/<domain>/…` and use the `auth:api` (JWT) middleware except auth endpoints.

### Request flow

Controller → FormRequest (validation) → **UseCase** (orchestration) → **RulesPipeline** → Services → Model + **OutboxEvent**.

- **UseCases** (`src/*/UseCases/*UseCase.php`) are the single entry point for business operations. Controllers are thin: validate, delegate, respond.
- **Rules** are pipeline stages with `handle($payload, Closure $next)` run via `Src\Shared\Pipelines\RulesPipeline::run($data, [RuleA::class, RuleB::class])`. They throw `BusinessRuleException` on failure (see `CreateOrderUseCase` for the canonical pattern).
- **Outbox pattern**: state-changing UseCases write to `outbox_events` inside the same DB transaction as the domain row. Do not dispatch jobs/events directly from UseCases — write an `OutboxEvent` row and let downstream workers consume it.
- **Ticket identifiers**: models using `Src\Shared\Traits\HasTicketIdentifiers` auto-populate `ticket_code` and `ticket_number` on `creating`. The model must implement `getTicketPrefix(): string` (e.g. `'ORD'`). Numbers come from the `sequences` table under a `lockForUpdate()`, so tests must use a real DB connection (SQLite `:memory:` is fine).

### API responses

Every controller extends `App\Http\Controllers\Controller`, which uses `Src\Shared\Traits\ApiResponse`. Always return via `$this->success(...)` / `$this->error(...)`. Response shape is `{ success, message, data? }`.

Exception rendering is centralized in `bootstrap/app.php` for any request matching `api/*`: `ValidationException`, `AuthenticationException`, `NotFoundHttpException`, and a catch-all that toggles debug details by `APP_DEBUG`. `BusinessRuleException` renders itself (with `error_code`) — do not add another handler for it.

### API docs

Scribe generates from controller docblocks (`@group`, `@apiResource`, `@apiResourceModel`, `@apiResourceAdditional`). Config in `config/scribe.php`; output in `.scribe/`.

## Testing

- Feature tests auto-use `RefreshDatabase` via `tests/Pest.php`.
- `phpunit.xml` pins `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` and `QUEUE_CONNECTION=sync` — don't override these in individual tests.
- `BCRYPT_ROUNDS=4` in tests — don't benchmark hashing.

## Conventions

- New business logic goes in a UseCase, not a controller or model. If validation is more than shape-checking (e.g. "customer exists", "no duplicates"), make it a Rule and put it in the pipeline.
- Shared cross-module code goes in `src/Shared/` (Traits, Services, Pipelines, Exceptions, Models like `OutboxEvent`).
- `App\Models\User` stays in `app/` because Laravel's auth config references it; module-owned models live under `src/<Module>/Models/`.
