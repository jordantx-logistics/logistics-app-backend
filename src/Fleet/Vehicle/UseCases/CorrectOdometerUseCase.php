<?php

namespace Src\Fleet\Vehicle\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Route\ValueObjects\Odometer;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\AuditLogger;

/**
 * Corrección autorizada del odómetro: la única vía para que retroceda, porque
 * una lectura errónea por exceso bloquearía todas las siguientes. Queda auditada.
 */
class CorrectOdometerUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $vehicleId, string|int|float $km, string $reason, AgencyScope $scope): Vehicle
    {
        return DB::transaction(function () use ($vehicleId, $km, $reason, $scope): Vehicle {
            $vehicle = Vehicle::query()->lockForUpdate()->find($vehicleId);

            if ($vehicle === null || ! $scope->covers((int) $vehicle->owner_agency_id)) {
                throw new BusinessRuleException(
                    message: 'The vehicle does not exist.',
                    errorCode: 'VEHICLE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            $before = (string) $vehicle->odometer_km;
            $corrected = Odometer::fromDecimal($km)->toDecimal();

            $vehicle->update(['odometer_km' => $corrected]);

            $this->audit->record(
                action: 'vehicle.odometer_corrected',
                entityType: 'vehicle',
                entityId: $vehicle->id,
                before: ['odometer_km' => $before],
                after: ['odometer_km' => $corrected, 'reason' => $reason],
            );

            return $vehicle;
        });
    }
}
