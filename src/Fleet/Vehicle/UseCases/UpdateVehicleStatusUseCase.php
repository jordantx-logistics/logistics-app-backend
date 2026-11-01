<?php

namespace Src\Fleet\Vehicle\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Mantenimiento y baja. Un vehículo en ruta no cambia de estado a mano: lo
 * devuelve a `available` el fin de la ruta.
 */
class UpdateVehicleStatusUseCase
{
    public function execute(int $vehicleId, string $status, AgencyScope $scope): Vehicle
    {
        return DB::transaction(function () use ($vehicleId, $status, $scope): Vehicle {
            $vehicle = Vehicle::query()->lockForUpdate()->find($vehicleId);

            if ($vehicle === null || ! $scope->covers((int) $vehicle->owner_agency_id)) {
                throw new BusinessRuleException(
                    message: 'The vehicle does not exist.',
                    errorCode: 'VEHICLE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if ($vehicle->status === Vehicle::STATUS_ON_ROUTE) {
                throw new BusinessRuleException(
                    message: 'A vehicle on route cannot change its status manually.',
                    errorCode: 'VEHICLE_ON_ROUTE',
                );
            }

            $vehicle->update(['status' => $status]);

            return $vehicle;
        });
    }
}
