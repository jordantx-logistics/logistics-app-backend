<?php

/**
 * Concurrencia de Flota: chofer y vehículo en una sola ruta, eventos sin duplicar,
 * odómetro monótono y tope de viático bajo peticiones simultáneas.
 *
 * Uso (MySQL desechable del perfil `concurrency` de docker-compose):
 *   docker compose --profile concurrency up -d --wait mysql-concurrency
 *   docker compose exec -e DB_HOST=mysql-concurrency -e DB_DATABASE=logistics_concurrency \
 *     -e DB_USERNAME=root -e DB_PASSWORD=root app php scripts/fleet-concurrency-check.php [rondas]
 *   docker compose --profile concurrency rm -sf mysql-concurrency
 *
 * Mismas condiciones que `finance-concurrency-check.php`: procesos reales, hace
 * `migrate:fresh`, se niega si la base no termina en `_concurrency` y termina con
 * 1 si se rompe un invariante o un caso de uso falla con algo que no sea un
 * `BusinessRuleException` (para el chofer, un 500).
 */

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Expense\UseCases\RegisterExpenseUseCase;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Route\UseCases\CompleteRouteSegmentUseCase;
use Src\Fleet\Route\UseCases\FinishRouteUseCase;
use Src\Fleet\Route\UseCases\RegisterMileageUseCase;
use Src\Fleet\Route\UseCases\StartRouteUseCase;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Organization\Models\Agency;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

const WORKERS = 8;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/support/WorkerPool.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')
    || config('database.default') !== 'mysql'
    || ! str_ends_with((string) config('database.connections.mysql.database'), '_concurrency')) {
    fwrite(STDERR, "Solo corre fuera de producción, en MySQL y en una base `*_concurrency`.\n");
    exit(1);
}

// La caché en memoria evita que `migrate:fresh` vacíe la de permisos de la app.
config(['cache.default' => 'array']);

if (($argv[1] ?? null) === '--worker') {
    WorkerPool::serve(execute(...));
    exit(0);
}

Artisan::call('migrate:fresh', ['--force' => true]);

$rounds = max(1, (int) ($argv[1] ?? 3));
$pool = new WorkerPool(__FILE__, WORKERS);

$agency = Agency::factory()->create([
    'code' => 'CONC1',
    'name' => 'Agencia concurrencia',
    'type' => 'branch',
    'ubigeo' => '150101',
    'timezone' => 'America/Lima',
    'is_active' => true,
]);

$failures = 0;

for ($round = 1; $round <= $rounds; $round++) {
    echo "Ronda {$round}/{$rounds}\n";

    foreach ([
        'mismo inicio repetido' => fn () => scenarioDoubleStart($pool, $agency),
        'un vehículo en varias rutas' => fn () => scenarioSameVehicle($pool, $agency),
        'un chofer en varias rutas' => fn () => scenarioSameDriver($pool, $agency),
        'mismo fin repetido' => fn () => scenarioDoubleFinish($pool, $agency),
        'lecturas de odómetro simultáneas' => fn () => scenarioOdometer($pool, $agency),
        'misma clave de lectura' => fn () => scenarioMileageKey($pool, $agency),
        'tope de viático' => fn () => scenarioPerDiemLimit($pool, $agency),
        'misma clave de gasto' => fn () => scenarioExpenseKey($pool, $agency),
        'mismo tramo repetido' => fn () => scenarioDoubleSegment($pool, $agency),
    ] as $name => $scenario) {
        $problems = $scenario();
        $failures += count($problems);

        printf("  %-40s %s\n", $name, $problems === [] ? 'OK' : 'FALLO');

        foreach ($problems as $problem) {
            echo "    - {$problem}\n";
        }
    }
}

$pool->stop();

echo $failures === 0 ? "Sin dobles asignaciones ni eventos duplicados.\n" : "{$failures} invariante(s) rotos.\n";

exit($failures === 0 ? 0 : 1);

// Escenarios: cada uno devuelve la lista de invariantes rotos.

function scenarioDoubleStart(WorkerPool $pool, Agency $agency): array
{
    $vehicle = vehicle($agency);
    $route = assignedRoute($agency, driver($agency), $vehicle);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'start',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'odometer' => '1100.00',
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);

    if (eventsOf('route.started', $route->id) !== 1) {
        $problems[] = 'route.started no se emitió exactamente una vez';
    }

    if (MileageLog::query()->where('route_id', $route->id)->count() !== 1) {
        $problems[] = 'el inicio repetido duplicó la lectura inicial';
    }

    return $problems;
}

function scenarioSameVehicle(WorkerPool $pool, Agency $agency): array
{
    $vehicle = vehicle($agency);

    $commands = array_map(function () use ($agency, $vehicle): array {
        $route = assignedRoute($agency, driver($agency), $vehicle);

        return [
            'op' => 'start',
            'user' => $route->driver->user_id,
            'agency' => $agency->id,
            'route' => $route->id,
            'odometer' => '1100.00',
        ];
    }, range(1, WORKERS));

    $replies = $pool->run($commands);
    $problems = unexpected($replies, ['VEHICLE_NOT_AVAILABLE']);
    $inProgress = FleetRoute::query()->where('vehicle_id', $vehicle->id)->where('status', FleetRoute::STATUS_IN_PROGRESS)->count();

    if ($inProgress !== 1 || okCount($replies) !== 1) {
        $problems[] = "el vehículo quedó en {$inProgress} rutas en curso";
    }

    return $problems;
}

function scenarioSameDriver(WorkerPool $pool, Agency $agency): array
{
    $driver = driver($agency);

    $commands = array_map(function () use ($agency, $driver): array {
        $route = assignedRoute($agency, $driver, vehicle($agency));

        return [
            'op' => 'start',
            'user' => $driver->user_id,
            'agency' => $agency->id,
            'route' => $route->id,
            'odometer' => '1100.00',
        ];
    }, range(1, WORKERS));

    $replies = $pool->run($commands);
    $problems = unexpected($replies, ['DRIVER_ON_ANOTHER_ROUTE']);
    $inProgress = FleetRoute::query()->where('driver_id', $driver->id)->where('status', FleetRoute::STATUS_IN_PROGRESS)->count();

    if ($inProgress !== 1 || okCount($replies) !== 1) {
        $problems[] = "el chofer quedó en {$inProgress} rutas en curso";
    }

    return $problems;
}

function scenarioDoubleFinish(WorkerPool $pool, Agency $agency): array
{
    $vehicle = vehicle($agency);
    $route = startedRoute($agency, driver($agency), $vehicle);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'finish',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'odometer' => '1500.00',
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);

    if (eventsOf('route.finished', $route->id) !== 1) {
        $problems[] = 'route.finished no se emitió exactamente una vez';
    }

    if ($route->refresh()->computed_km !== '500.00') {
        $problems[] = "computed_km es {$route->computed_km} y debería ser 500.00";
    }

    if ($vehicle->refresh()->status !== Vehicle::STATUS_AVAILABLE) {
        $problems[] = 'el vehículo no quedó disponible';
    }

    return $problems;
}

function scenarioOdometer(WorkerPool $pool, Agency $agency): array
{
    $vehicle = vehicle($agency);
    $route = startedRoute($agency, driver($agency), $vehicle);

    $replies = $pool->run(array_map(fn (int $i): array => [
        'op' => 'mileage',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'odometer' => (string) (1000 + $i * 10),
        'key' => null,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['MILEAGE_NOT_MONOTONIC']);

    // Por id, cada lectura aceptada debe ser mayor o igual que la anterior.
    $readings = MileageLog::query()->where('route_id', $route->id)->orderBy('id')->pluck('odometer_km')->map(fn ($km) => (float) $km)->all();
    $sorted = $readings;
    sort($sorted);

    if ($readings !== $sorted) {
        $problems[] = 'el odómetro retrocedió: '.implode(', ', $readings);
    }

    if ((float) $vehicle->refresh()->odometer_km !== end($readings)) {
        $problems[] = 'vehicles.odometer_km no coincide con la última lectura';
    }

    return $problems;
}

function scenarioMileageKey(WorkerPool $pool, Agency $agency): array
{
    $route = startedRoute($agency, driver($agency), vehicle($agency));
    $key = uniqid('km-', true);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'mileage',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'odometer' => '1200.00',
        'key' => $key,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);

    if (MileageLog::query()->where('idempotency_key', $key)->count() !== 1) {
        $problems[] = 'la misma clave duplicó la lectura';
    }

    return $problems;
}

function scenarioPerDiemLimit(WorkerPool $pool, Agency $agency): array
{
    $route = startedRoute($agency, driver($agency), vehicle($agency));

    // El tope por defecto es 500.00: solo caben dos viáticos de 200.00.
    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'expense',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'amount' => '200.00',
        'key' => uniqid('exp-', true),
    ], range(1, WORKERS)));

    $problems = unexpected($replies, ['EXPENSE_PER_DIEM_LIMIT']);
    $accepted = okCount($replies);

    if ($accepted !== 2) {
        $problems[] = "se aceptaron {$accepted} viáticos de 200.00 con un tope de 500.00";
    }

    return $problems;
}

function scenarioExpenseKey(WorkerPool $pool, Agency $agency): array
{
    $route = startedRoute($agency, driver($agency), vehicle($agency));
    $key = uniqid('exp-', true);

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'expense',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'amount' => '100.00',
        'key' => $key,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);

    if (Expense::query()->where('idempotency_key', $key)->count() !== 1) {
        $problems[] = 'la misma clave duplicó el gasto';
    }

    return $problems;
}

function scenarioDoubleSegment(WorkerPool $pool, Agency $agency): array
{
    $route = startedRoute($agency, driver($agency), vehicle($agency));
    $segment = RouteSegment::query()->where('route_id', $route->id)->sole();

    $replies = $pool->run(array_map(fn (): array => [
        'op' => 'segment',
        'user' => $route->driver->user_id,
        'agency' => $agency->id,
        'route' => $route->id,
        'segment' => $segment->id,
    ], range(1, WORKERS)));

    $problems = unexpected($replies, []);

    if (eventsOf('route.segment-completed', $route->id) !== 1) {
        $problems[] = 'route.segment-completed no se emitió exactamente una vez';
    }

    return $problems;
}

// Datos de apoyo.

function driver(Agency $agency): Driver
{
    return Driver::factory()->create(['home_agency_id' => $agency->id]);
}

function vehicle(Agency $agency): Vehicle
{
    return Vehicle::factory()->create(['owner_agency_id' => $agency->id, 'odometer_km' => '1000.00']);
}

function assignedRoute(Agency $agency, Driver $driver, Vehicle $vehicle): FleetRoute
{
    $route = FleetRoute::factory()->create([
        'origin_agency_id' => $agency->id,
        'destination_agency_id' => $agency->id,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'status' => FleetRoute::STATUS_ASSIGNED,
    ]);

    RouteSegment::query()->create([
        'route_id' => $route->id,
        'sequence' => 1,
        'from_agency_id' => $agency->id,
        'to_agency_id' => $agency->id,
        'distance_km' => '100.00',
        'status' => RouteSegment::STATUS_PENDING,
    ]);

    return $route;
}

function startedRoute(Agency $agency, Driver $driver, Vehicle $vehicle): FleetRoute
{
    $route = assignedRoute($agency, $driver, $vehicle);

    Auth::setUser(User::query()->findOrFail($driver->user_id));
    app(StartRouteUseCase::class)->execute($route->id, '1000.00', null, AgencyScope::forAgencies([$agency->id]));

    return $route->refresh();
}

function eventsOf(string $type, int $routeId): int
{
    return OutboxEvent::query()->where('event_type', $type)->get()->where('payload.route_id', $routeId)->count();
}

function okCount(array $replies): int
{
    return count(array_filter($replies, fn (array $reply): bool => $reply['ok']));
}

/**
 * @param  list<string>  $allowed  Rechazos de negocio legítimos en este escenario.
 * @return list<string>
 */
function unexpected(array $replies, array $allowed): array
{
    $codes = [];

    foreach ($replies as $reply) {
        if (! $reply['ok'] && ! in_array($reply['code'], $allowed, true)) {
            $codes[] = $reply['code'];
        }
    }

    return array_map(
        fn (string $code, int $times): string => "{$times}× {$code}",
        array_keys(array_count_values($codes)),
        array_count_values($codes),
    );
}

function execute(array $command): array
{
    Auth::setUser(User::query()->findOrFail($command['user']));
    $scope = AgencyScope::forAgencies([$command['agency']]);

    try {
        match ($command['op']) {
            'start' => app(StartRouteUseCase::class)->execute($command['route'], $command['odometer'], null, $scope),
            'finish' => app(FinishRouteUseCase::class)->execute($command['route'], $command['odometer'], null, $scope),
            'segment' => app(CompleteRouteSegmentUseCase::class)->execute($command['route'], $command['segment'], $scope),
            'mileage' => app(RegisterMileageUseCase::class)->execute($command['route'], [
                'odometer_km' => $command['odometer'],
                'idempotency_key' => $command['key'],
            ], null, $scope),
            'expense' => app(RegisterExpenseUseCase::class)->execute([
                'route_id' => $command['route'],
                'type' => Expense::TYPE_PER_DIEM,
                'amount' => $command['amount'],
                'idempotency_key' => $command['key'],
            ], null, $scope),
        };

        return ['ok' => true];
    } catch (BusinessRuleException $exception) {
        return ['ok' => false, 'code' => $exception->errorCode];
    } catch (Throwable $exception) {
        return ['ok' => false, 'code' => class_basename($exception).': '.substr($exception->getMessage(), 0, 120)];
    }
}
