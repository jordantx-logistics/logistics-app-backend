<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\OdometerRecorder;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Inicia la ruta. Es idempotente: iniciar una ruta ya en curso no repite efectos.
 *
 * Bloquea en orden ruta → chofer → vehículo antes de cualquier lectura sin
 * bloqueo: en REPEATABLE READ esa lectura fijaría la foto de la transacción y no
 * vería la ruta que otro acaba de iniciar con el mismo chofer.
 */
class StartRouteUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
        private readonly OdometerRecorder $odometer,
    ) {}

    public function execute(int $routeId, string|int|float $odometerKm, mixed $recordedAt, AgencyScope $scope): FleetRoute
    {
        return DB::transaction(function () use ($routeId, $odometerKm, $recordedAt, $scope): FleetRoute {
            $route = FleetRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                throw new BusinessRuleException(
                    message: 'The route does not exist.',
                    errorCode: 'ROUTE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if ($route->driver_id === null || $route->vehicle_id === null) {
                $this->guard->assertCanOperate($route, $scope);

                throw new BusinessRuleException(
                    message: 'A route cannot start without a driver and a vehicle.',
                    errorCode: 'ROUTE_REQUIRES_RESOURCES',
                );
            }

            $driver = Driver::query()->lockForUpdate()->findOrFail($route->driver_id);
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($route->vehicle_id);

            $this->guard->assertCanOperate($route, $scope, $driver);

            if ($route->status === FleetRoute::STATUS_IN_PROGRESS) {
                return $route->load('segments');
            }

            if ($route->status !== FleetRoute::STATUS_ASSIGNED) {
                throw new BusinessRuleException(
                    message: "A route in [{$route->status}] cannot start.",
                    errorCode: 'ROUTE_INVALID_TRANSITION',
                );
            }

            if (! $driver->isActive()) {
                throw new BusinessRuleException(
                    message: 'The driver is not active.',
                    errorCode: 'DRIVER_NOT_ACTIVE',
                );
            }

            if (! $driver->licenseValidOn(now())) {
                throw new BusinessRuleException(
                    message: 'The driver license has expired.',
                    errorCode: 'DRIVER_LICENSE_EXPIRED',
                );
            }

            if (! $vehicle->isAvailable()) {
                throw new BusinessRuleException(
                    message: "The vehicle is not available: it is [{$vehicle->status}].",
                    errorCode: 'VEHICLE_NOT_AVAILABLE',
                );
            }

            $driverBusy = FleetRoute::query()
                ->where('driver_id', $driver->id)
                ->where('status', FleetRoute::STATUS_IN_PROGRESS)
                ->exists();

            if ($driverBusy) {
                throw new BusinessRuleException(
                    message: 'The driver is already on another route.',
                    errorCode: 'DRIVER_ON_ANOTHER_ROUTE',
                );
            }

            $log = $this->odometer->log($route, $vehicle, $odometerKm, $recordedAt);
            $vehicle->update(['status' => Vehicle::STATUS_ON_ROUTE]);

            $route->update([
                'status' => FleetRoute::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type' => 'route.started',
                'payload' => [
                    'route_id' => $route->id,
                    'ticket_number' => $route->ticket_number,
                    'origin_agency_id' => $route->origin_agency_id,
                    'destination_agency_id' => $route->destination_agency_id,
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'odometer_km' => $log->odometer_km,
                    'started_at' => $route->started_at?->toIso8601String(),
                ],
            ]);

            return $route->load('segments');
        });
    }
}
