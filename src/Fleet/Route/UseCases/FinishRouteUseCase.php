<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Route\Services\OdometerRecorder;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Fleet\Route\Services\RouteSummaryService;
use Src\Fleet\Route\ValueObjects\Odometer;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Contracts\Metrics;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Finaliza la ruta: lectura final, kilometraje, vehículo libre y `route.finished`.
 * Es idempotente. Un consumo fuera de rango alerta, pero no bloquea.
 */
class FinishRouteUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
        private readonly OdometerRecorder $odometer,
        private readonly RouteSummaryService $summary,
        private readonly Metrics $metrics,
    ) {}

    public function execute(int $routeId, string|int|float $odometerKm, mixed $recordedAt, AgencyScope $scope): FleetRoute
    {
        $alreadyFinished = false;

        $route = DB::transaction(function () use ($routeId, $odometerKm, $recordedAt, $scope, &$alreadyFinished): FleetRoute {
            $route = FleetRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                throw new BusinessRuleException(
                    message: 'The route does not exist.',
                    errorCode: 'ROUTE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            $this->guard->assertCanOperate($route, $scope);

            if ($route->status === FleetRoute::STATUS_FINISHED) {
                $alreadyFinished = true;

                return $route->load('segments');
            }

            if ($route->status !== FleetRoute::STATUS_IN_PROGRESS) {
                throw new BusinessRuleException(
                    message: 'Only a route in progress can be finished.',
                    errorCode: 'ROUTE_NOT_IN_PROGRESS',
                );
            }

            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($route->vehicle_id);
            $log = $this->odometer->log($route, $vehicle, $odometerKm, $recordedAt);

            $first = MileageLog::query()->where('route_id', $route->id)->orderBy('id')->value('odometer_km');
            $km = Odometer::fromDecimal((string) $log->odometer_km)->kmSince(Odometer::fromDecimal((string) $first));

            $vehicle->update(['status' => Vehicle::STATUS_AVAILABLE]);

            $route->update([
                'status' => FleetRoute::STATUS_FINISHED,
                'finished_at' => now(),
                'computed_km' => number_format($km, 2, '.', ''),
            ]);

            OutboxEvent::create([
                'event_type' => 'route.finished',
                'payload' => [
                    'route_id' => $route->id,
                    'ticket_number' => $route->ticket_number,
                    'driver_id' => $route->driver_id,
                    'vehicle_id' => $route->vehicle_id,
                    'odometer_km' => $log->odometer_km,
                    'computed_km' => $route->computed_km,
                    'finished_at' => $route->finished_at?->toIso8601String(),
                    'segments_completed' => RouteSegment::query()
                        ->where('route_id', $route->id)
                        ->where('status', RouteSegment::STATUS_COMPLETED)
                        ->count(),
                ],
            ]);

            return $route->load('segments');
        });

        if (! $alreadyFinished) {
            $this->alertIfAnomalous($route);
        }

        return $route;
    }

    private function alertIfAnomalous(FleetRoute $route): void
    {
        $summary = $this->summary->for($route);

        if (! $summary['is_anomalous']) {
            return;
        }

        Log::warning('fleet.consumption_anomaly', [
            'route_id' => $route->id,
            'vehicle_id' => $route->vehicle_id,
            'km_per_liter' => $summary['km_per_liter'],
        ]);

        $this->metrics->increment(
            'fleet_consumption_anomalies_total',
            help: 'Rutas finalizadas con un consumo fuera del rango normal.',
        );
    }
}
