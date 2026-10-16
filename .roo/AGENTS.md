# AGENTS.md

This file provides guidance to agents when working with code in this repository.

## Commands

- `composer dev` — starts server, queue listener, and Vite concurrently
- `composer test` — clears config and runs tests; append `--filter=TestName` for filtering
- `php artisan test tests/Feature/OrderTest.php` — run single test file
- `./vendor/bin/pest --filter='it creates an order'` — run single test by name
- `./vendor/bin/pint` — format PHP code
- `php artisan migrate:fresh --seed` — reset and seed database

## Critical Gotchas

### Module Creation
When creating a module with `php artisan make:module`, no registration step is needed: `ModuleServiceProvider` discovers `src/*/routes.php` and `src/*/*/routes.php` automatically.

### Routing
All API routes are loaded dynamically by `ModuleServiceProvider::loadModuleRoutes()`, which globs every module `routes.php` under `src/`.

### Business Logic Flow
Controllers are thin wrappers. All business logic must go in **UseCases**. Validation rules belong in FormRequests or Rule classes within a RulesPipeline.

### Outbox Pattern
State-changing operations must write to `outbox_events` table within the same DB transaction as the domain model. Never dispatch jobs/events directly from UseCases.

### Ticket Identifiers
Models using `Src\Shared\Traits\HasTicketIdentifiers` must implement `getTicketPrefix(): string` (e.g., `'ORD'`). Numbers are fetched from `sequences` table under `lockForUpdate()`.

### Testing
- `phpunit.xml` uses `DB_CONNECTION=sqlite` with `:memory:` database — don't override this
- `BCRYPT_ROUNDS=4` in tests — don't benchmark password hashing
- Feature tests auto-load `RefreshDatabase` via `tests/Pest.php`

## Architecture

### Response Format
All API responses must use `$this->success()` or `$this->error()` from `App\Http\Controllers\Controller` (which uses `Src\Shared\Traits\ApiResponse`). Response shape: `{ success, message, data? }`.

### Exception Handling
`BusinessRuleException` renders itself with `error_code` field. Do not add custom exception handlers for it.

### API Documentation
Scribe generates docs from controller docblocks (`@group`, `@apiResource`, `@apiResourceModel`, `@apiResourceAdditional`). Output is in `.scribe/` directory.
