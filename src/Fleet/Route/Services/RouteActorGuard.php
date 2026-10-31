<?php

namespace Src\Fleet\Route\Services;

use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * Quién opera una ruta en concreto. El permiso de la petición no basta: el chofer
 * solo opera la suya. Quien tiene `routes.manage` en la agencia de origen puede
 * hacerlo en su nombre, porque hasta la Fase 8 no hay app móvil.
 */
class RouteActorGuard
{
    public function assertCanOperate(FleetRoute $route, AgencyScope $scope, ?Driver $driver = null): void
    {
        $user = auth()->user();
        $driver ??= $route->driver_id === null ? null : Driver::query()->find($route->driver_id);

        if ($driver !== null && (int) $driver->user_id === (int) $user?->getAuthIdentifier()) {
            return;
        }

        if ($user?->can('routes.manage') && $scope->covers((int) $route->origin_agency_id)) {
            return;
        }

        throw new BusinessRuleException(
            message: 'Only the assigned driver or a supervisor of the agency can operate this route.',
            errorCode: 'ROUTE_NOT_ASSIGNED_TO_USER',
            statusCode: 403,
        );
    }
}
