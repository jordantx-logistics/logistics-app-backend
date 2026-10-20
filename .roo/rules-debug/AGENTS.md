# Debug Mode Rules

## Log Locations
- Application logs: **stderr** as one JSON line per record (`LOG_CHANNEL=json`). Read them with `docker compose logs -f app`. `storage/logs/laravel.log` is **not** the default channel anymore and stays empty unless a channel is configured for it
- Every request carries `request_id` (honours an incoming `X-Request-Id` and echoes it back) plus `user_id`, `route`, `status` and `duration_ms` when applicable: filter by it to follow one request (`LogHttpRequest` emits the `http_request` line)
- Inside a container without the Docker CLI: `docker compose exec app sh -c 'tail -f /dev/null'` is not needed — run the command and read stderr from the same `docker compose exec`/`run` output
- **Octane gotcha**: if the app's JSON lines show up as `ERROR unknown error.`, the worker was started without `--log-level` (`OCTANE_LOG_LEVEL`), so Octane parsed the app's stderr as Caddy's JSON log. Recreate the service (`docker compose up -d --force-recreate app`)

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
