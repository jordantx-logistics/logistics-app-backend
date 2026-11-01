<?php

namespace Src\Fleet\Route\Adapters;

use Src\Fleet\Route\Models\FleetRoute;
use Src\Shared\Contracts\RouteDirectory;
use Src\Shared\DTOs\RouteSnapshot;

class EloquentRouteDirectory implements RouteDirectory
{
    public function findByTicketNumber(string $ticketNumber): ?RouteSnapshot
    {
        $route = FleetRoute::query()->where('ticket_number', $ticketNumber)->first();

        if ($route === null) {
            return null;
        }

        return new RouteSnapshot(
            id: $route->id,
            ticketNumber: $route->ticket_number,
            status: $route->status,
            driverId: $route->driver_id,
            vehicleId: $route->vehicle_id,
        );
    }
}
