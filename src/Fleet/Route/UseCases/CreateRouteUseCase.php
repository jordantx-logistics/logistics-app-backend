<?php

namespace Src\Fleet\Route\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\RouteSegment;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Planifica una ruta con sus tramos. Los tramos deben encadenar sin huecos desde
 * el origen hasta el destino: el kilometraje y la secuencia dependen de ello.
 */
class CreateRouteUseCase
{
    public function __construct(
        private readonly AgencyDirectory $agencies,
    ) {}

    /**
     * @param  array{origin_agency_id: int, destination_agency_id: int, planned_departure_at: string, planned_arrival_at?: ?string, segments: list<array{from_agency_id: int, to_agency_id: int, distance_km: float|int|string}>}  $data
     */
    public function execute(array $data, AgencyScope $scope): FleetRoute
    {
        $origin = (int) $data['origin_agency_id'];
        $destination = (int) $data['destination_agency_id'];

        if (! $scope->covers($origin)) {
            throw new BusinessRuleException(
                message: 'The origin agency is outside your scope.',
                errorCode: 'FLEET_AGENCY_SCOPE',
                statusCode: 403,
            );
        }

        $this->assertSegmentsChain($data['segments'], $origin, $destination);

        foreach ($data['segments'] as $segment) {
            foreach ([$segment['from_agency_id'], $segment['to_agency_id']] as $agencyId) {
                if (! $this->agencies->agencyIsActive((int) $agencyId)) {
                    throw new BusinessRuleException(
                        message: 'An agency of the route does not exist or is inactive.',
                        errorCode: 'FLEET_AGENCY_INVALID',
                    );
                }
            }
        }

        return DB::transaction(function () use ($data, $origin, $destination): FleetRoute {
            $route = FleetRoute::create([
                'origin_agency_id' => $origin,
                'destination_agency_id' => $destination,
                'status' => FleetRoute::STATUS_PLANNED,
                'planned_departure_at' => $data['planned_departure_at'],
                'planned_arrival_at' => $data['planned_arrival_at'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($data['segments']) as $index => $segment) {
                RouteSegment::create([
                    'route_id' => $route->id,
                    'sequence' => $index + 1,
                    'from_agency_id' => (int) $segment['from_agency_id'],
                    'to_agency_id' => (int) $segment['to_agency_id'],
                    'distance_km' => $segment['distance_km'],
                    'status' => RouteSegment::STATUS_PENDING,
                ]);
            }

            return $route->load('segments');
        });
    }

    /**
     * @param  list<array{from_agency_id: int, to_agency_id: int}>  $segments
     */
    private function assertSegmentsChain(array $segments, int $origin, int $destination): void
    {
        $current = $origin;

        foreach ($segments as $segment) {
            if ((int) $segment['from_agency_id'] !== $current || (int) $segment['to_agency_id'] === $current) {
                throw $this->invalidSegments();
            }

            $current = (int) $segment['to_agency_id'];
        }

        if ($current !== $destination) {
            throw $this->invalidSegments();
        }
    }

    private function invalidSegments(): BusinessRuleException
    {
        return new BusinessRuleException(
            message: 'The segments must chain from the origin to the destination of the route.',
            errorCode: 'ROUTE_SEGMENTS_INVALID',
        );
    }
}
