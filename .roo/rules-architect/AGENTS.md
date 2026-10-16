# Architect Mode Rules

## Modular Monolith Pattern
- Each module is self-contained with its own Adapters, Controllers, Events, Exceptions, Models, Requests, Resources, Rules, Services and UseCases
- Modules do **not** import each other's classes (ADR-0006). They talk through a **port** in `src/Shared/Contracts/` (e.g. `CustomerDirectory`, `AgencyDirectory`) or an event
- The adapter that knows the foreign model lives in the owning context (`src/Customer/Adapters/`); the binding lives only in `app/Providers/DomainServiceProvider.php` (composition root)
- No foreign keys between contexts: cross-context references are plain indexed columns validated in a Rule through a port
- `tests/Feature/Architecture/ContextBoundariesTest.php` fails on a cross-context import or FK

## Routing Architecture
```
routes/web.php — serves welcome page only
ModuleServiceProvider — discovers and loads every module routes.php
Each module's routes.php — defines api/v1/<domain>/ routes
```

## Request Flow Architecture
```
Client Request
    ↓
ModuleServiceProvider (route loading)
    ↓
Controller (thin wrapper)
    ↓
FormRequest (validation)
    ↓
UseCase (orchestration)
    ↓
RulesPipeline (business rules)
    ↓
Services (business logic)
    ↓
Model + OutboxEvent (persistence)
```

## Outbox Pattern Implementation
- State changes write to `outbox_events` table in same DB transaction
- Downstream workers consume outbox events (not dispatched from UseCases)
- `sequences` table provides atomic ticket number generation

## Ticket Identifier System
- Models implement `Src\Shared\Traits\HasTicketIdentifiers`
- Must implement `getTicketPrefix(): string` (e.g., `'ORD'` for orders)
- Numbers fetched from `sequences` table under `lockForUpdate()`
- Auto-populates `ticket_code` and `ticket_number` on model `creating`

## API Response Standardization
- All responses use `ApiResponse` trait methods: `$this->success()` / `$this->error()`
- Response shape: `{ success: bool, message: string, data?: mixed }`
- `BusinessRuleException` renders itself with `error_code` field

## Module Registration
- `ModuleServiceProvider` discovers `src/*/routes.php` and `src/*/*/routes.php` with `glob()` (sorted)
- Adding a module's `routes.php` is enough; there is no list to maintain
- `tests/Feature/Architecture/ModuleRoutesTest.php` guards that a context's `api/…` prefix is registered

## Current Modules
- `Auth` — authentication (login/register)
- `Customer` — persons and companies
- `Organization` — agencies
- `Logistics/Order` — order management
- `Logistics/Payment` — payment processing
- `Permission` — roles and permissions
- `System` — health checks (`/api/v1/health`) and Prometheus metrics (`/metrics`)
- `Shared` — cross-module kernel (Traits, Pipelines, Exceptions, Services, Contracts)
