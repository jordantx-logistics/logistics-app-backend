<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Asigna chofer y vehículo. La licencia debe seguir vigente en la fecha de salida
 * planificada. Se puede reasignar mientras la ruta no haya salido.
 */
class AssignRouteUseCase
{
    public function execute(int $routeId, int $driverId, int $vehicleId, AgencyScope $scope): FleetRoute
    {
        return DB::transaction(function () use ($routeId, $driverId, $vehicleId, $scope): FleetRoute {
            $route = FleetRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                throw new BusinessRuleException(
                    message: 'The route does not exist.',
                    errorCode: 'ROUTE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $scope->covers((int) $route->origin_agency_id)) {
                throw new BusinessRuleException(
                    message: 'The route belongs to an agency outside your scope.',
                    errorCode: 'FLEET_AGENCY_SCOPE',
                    statusCode: 403,
                );
            }

            if (! in_array($route->status, [FleetRoute::STATUS_PLANNED, FleetRoute::STATUS_ASSIGNED], true)) {
                throw new BusinessRuleException(
                    message: "A route in [{$route->status}] cannot be assigned.",
                    errorCode: 'ROUTE_INVALID_TRANSITION',
                );
            }

            $driver = Driver::query()->forAgencyScope($scope)->find($driverId);

            if ($driver === null) {
                throw new BusinessRuleException(
                    message: 'The driver does not exist.',
                    errorCode: 'DRIVER_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $driver->isActive()) {
                throw new BusinessRuleException(
                    message: 'The driver is not active.',
                    errorCode: 'DRIVER_NOT_ACTIVE',
                );
            }

            if (! $driver->licenseValidOn($route->planned_departure_at)) {
                throw new BusinessRuleException(
                    message: 'The driver license expires before the planned departure.',
                    errorCode: 'DRIVER_LICENSE_EXPIRED',
                );
            }

            $vehicle = Vehicle::query()->forAgencyScope($scope)->find($vehicleId);

            if ($vehicle === null) {
                throw new BusinessRuleException(
                    message: 'The vehicle does not exist.',
                    errorCode: 'VEHICLE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (! $vehicle->isAvailable()) {
                throw new BusinessRuleException(
                    message: "The vehicle is not available: it is [{$vehicle->status}].",
                    errorCode: 'VEHICLE_NOT_AVAILABLE',
                );
            }

            $route->update([
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'status' => FleetRoute::STATUS_ASSIGNED,
            ]);

            return $route->load('segments');
        });
    }
}
