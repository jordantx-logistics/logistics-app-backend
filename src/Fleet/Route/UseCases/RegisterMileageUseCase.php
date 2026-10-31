<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\Services\FleetEvidenceStore;
use Src\Fleet\Route\Services\OdometerRecorder;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Throwable;

/**
 * Lectura de odómetro durante la ruta. `idempotency_key` tolera los reintentos
 * de la app de Flota sin duplicar la lectura.
 */
class RegisterMileageUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
        private readonly OdometerRecorder $odometer,
        private readonly FleetEvidenceStore $evidence,
    ) {}

    /**
     * @param  array{odometer_km: string|int|float, recorded_at?: mixed, idempotency_key?: ?string}  $data
     */
    public function execute(int $routeId, array $data, ?UploadedFile $photo, AgencyScope $scope): MileageLog
    {
        $key = $data['idempotency_key'] ?? null;
        $photoPath = null;

        try {
            return DB::transaction(function () use ($routeId, $data, $photo, $scope, $key, &$photoPath): MileageLog {
                $route = FleetRoute::query()->lockForUpdate()->find($routeId);

                if ($route === null) {
                    throw new BusinessRuleException(
                        message: 'The route does not exist.',
                        errorCode: 'ROUTE_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                $this->guard->assertCanOperate($route, $scope);

                $existing = $key === null ? null : MileageLog::query()->where('idempotency_key', $key)->first();

                if ($existing !== null) {
                    return $existing;
                }

                if ($route->status !== FleetRoute::STATUS_IN_PROGRESS) {
                    throw new BusinessRuleException(
                        message: 'Mileage can only be registered on a route in progress.',
                        errorCode: 'ROUTE_NOT_IN_PROGRESS',
                    );
                }

                $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($route->vehicle_id);
                $photoPath = $photo === null ? null : $this->evidence->store($photo, $route->id, 'odometer');

                return $this->odometer->log($route, $vehicle, $data['odometer_km'], $data['recorded_at'] ?? null, $photoPath, $key);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->evidence->delete($photoPath);

            return MileageLog::query()->where('idempotency_key', $key)->first() ?? throw $exception;
        } catch (Throwable $exception) {
            $this->evidence->delete($photoPath);

            throw $exception;
        }
    }
}
