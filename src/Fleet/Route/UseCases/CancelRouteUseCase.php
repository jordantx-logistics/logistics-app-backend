<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

class CancelRouteUseCase
{
    public function execute(int $routeId, string $reason, AgencyScope $scope): FleetRoute
    {
        return DB::transaction(function () use ($routeId, $reason, $scope): FleetRoute {
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
                    message: "A route in [{$route->status}] cannot be cancelled.",
                    errorCode: 'ROUTE_INVALID_TRANSITION',
                );
            }

            $route->update([
                'status' => FleetRoute::STATUS_CANCELLED,
                'cancel_reason' => $reason,
            ]);

            OutboxEvent::create([
                'event_type' => 'route.cancelled',
                'payload' => [
                    'route_id' => $route->id,
                    'ticket_number' => $route->ticket_number,
                    'reason' => $reason,
                ],
            ]);

            return $route->load('segments');
        });
    }
}
