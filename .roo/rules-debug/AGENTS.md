# Debug Mode Rules

## Log Locations
- Application logs: `storage/logs/laravel.log`
- Queue worker logs: Check output channel for "queue" process
- Vite build errors: Check browser console and `storage/logs/laravel.log`

## Queue Debugging
- Queue uses sync connection in tests (`phpunit.xml`)
- In production, check queue worker output for processing errors
- Outbox events are written to DB, not dispatched — check `outbox_events` table

## Database Debugging
- Tests use SQLite `:memory:` — data persists only during test execution
- For debugging test failures, use a real database connection temporarily
- `sequences` table is required for ticket number generation — verify it exists

## Common Issues
- **Routes not loading**: the module needs a `src/<Context>/routes.php` (or `src/<Context>/<Sub>/routes.php`) declaring its `api/…` prefix
- **Ticket numbers not generating**: Model missing `getTicketPrefix()` method implementation
- **Outbox events not processing**: Check queue worker is running
- **BusinessRuleException not rendering**: Ensure exception is thrown from UseCase, not controller

## Testing Gotchas
- `RefreshDatabase` trait creates fresh `:memory:` DB before each test file
- Tests must not rely on data persisting across different test files
- Use `->refreshDatabase()` manually if you need to reset state mid-test
