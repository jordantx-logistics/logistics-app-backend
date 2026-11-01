<?php

namespace Src\Fleet\Route\Services;

use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\ValueObjects\Odometer;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;

/**
 * Valida y aplica una lectura de odómetro. El vehículo debe llegar bloqueado por
 * quien llama: la comparación y la escritura tienen que ser atómicas.
 */
class OdometerRecorder
{
    public function advance(Vehicle $vehicle, string|int|float $km): Odometer
    {
        $reading = Odometer::fromDecimal($km);

        if ($reading->isBelow(Odometer::fromDecimal((string) $vehicle->odometer_km))) {
            throw new BusinessRuleException(
                message: 'The odometer cannot go backwards from the last known reading.',
                errorCode: 'MILEAGE_NOT_MONOTONIC',
            );
        }

        $vehicle->update(['odometer_km' => $reading->toDecimal()]);

        return $reading;
    }

    public function log(
        FleetRoute $route,
        Vehicle $vehicle,
        string|int|float $km,
        mixed $recordedAt = null,
        ?string $photoPath = null,
        ?string $idempotencyKey = null,
    ): MileageLog {
        $reading = $this->advance($vehicle, $km);

        return MileageLog::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'odometer_km' => $reading->toDecimal(),
            'recorded_at' => $recordedAt ?? now(),
            'recorded_by' => (int) auth()->id(),
            'photo_path' => $photoPath,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
