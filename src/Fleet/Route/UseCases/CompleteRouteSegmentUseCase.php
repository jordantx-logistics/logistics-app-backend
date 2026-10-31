<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Completa un tramo, siempre en orden de `sequence`. Repetirlo no duplica el
 * evento: la app de Flota reintentará al recuperar la red.
 */
class CompleteRouteSegmentUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
    ) {}

    public function execute(int $routeId, int $segmentId, AgencyScope $scope): RouteSegment
    {
        return DB::transaction(function () use ($routeId, $segmentId, $scope): RouteSegment {
            $route = FleetRoute::query()->lockForUpdate()->find($routeId);

            if ($route === null) {
                throw new BusinessRuleException(
                    message: 'The route does not exist.',
                    errorCode: 'ROUTE_NOT_FOUND',
                    statusCode: 404,
                );
            }

            $this->guard->assertCanOperate($route, $scope);

            if ($route->status !== FleetRoute::STATUS_IN_PROGRESS) {
                throw new BusinessRuleException(
                    message: 'Segments can only be completed on a route in progress.',
                    errorCode: 'ROUTE_NOT_IN_PROGRESS',
                );
            }

            $segment = RouteSegment::query()->where('route_id', $route->id)->find($segmentId);

            if ($segment === null) {
                throw new BusinessRuleException(
                    message: 'The segment does not belong to this route.',
                    errorCode: 'ROUTE_SEGMENT_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if ($segment->status === RouteSegment::STATUS_COMPLETED) {
                return $segment;
            }

            $previousPending = RouteSegment::query()
                ->where('route_id', $route->id)
                ->where('sequence', '<', $segment->sequence)
                ->where('status', RouteSegment::STATUS_PENDING)
                ->exists();

            if ($previousPending) {
                throw new BusinessRuleException(
                    message: 'The previous segments must be completed first.',
                    errorCode: 'ROUTE_SEGMENT_OUT_OF_SEQUENCE',
                );
            }

            $segment->update([
                'status' => RouteSegment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            OutboxEvent::create([
                'event_type' => 'route.segment-completed',
                'payload' => [
                    'route_id' => $route->id,
                    'ticket_number' => $route->ticket_number,
                    'segment_id' => $segment->id,
                    'sequence' => $segment->sequence,
                    'to_agency_id' => $segment->to_agency_id,
                    'completed_at' => $segment->completed_at?->toIso8601String(),
                ],
            ]);

            return $segment;
        });
    }
}
