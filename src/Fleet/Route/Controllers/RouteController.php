<?php

namespace Src\Fleet\Route\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\Requests\AssignRouteRequest;
use Src\Fleet\Route\Requests\CancelRouteRequest;
use Src\Fleet\Route\Requests\ListRoutesRequest;
use Src\Fleet\Route\Requests\RegisterMileageRequest;
use Src\Fleet\Route\Requests\RouteOdometerRequest;
use Src\Fleet\Route\Requests\StoreRouteRequest;
use Src\Fleet\Route\Resources\MileageLogResource;
use Src\Fleet\Route\Resources\RouteResource;
use Src\Fleet\Route\Resources\RouteSegmentResource;
use Src\Fleet\Route\Services\FleetEvidenceStore;
use Src\Fleet\Route\Services\RouteSummaryService;
use Src\Fleet\Route\UseCases\AssignRouteUseCase;
use Src\Fleet\Route\UseCases\CancelRouteUseCase;
use Src\Fleet\Route\UseCases\CompleteRouteSegmentUseCase;
use Src\Fleet\Route\UseCases\CreateRouteUseCase;
use Src\Fleet\Route\UseCases\FinishRouteUseCase;
use Src\Fleet\Route\UseCases\RegisterMileageUseCase;
use Src\Fleet\Route\UseCases\StartRouteUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Fleet - Routes
 *
 * Rutas troncales: planificación, asignación, ejecución y kilometraje.
 */
class RouteController extends Controller
{
    /**
     * List routes
     *
     * @queryParam status string One status or a comma-separated list. Example: assigned,in_progress
     * @queryParam agency integer Origin or destination agency. Example: 1
     * @queryParam driver_id integer Filter by driver. Example: 1
     * @queryParam vehicle_id integer Filter by vehicle. Example: 1
     * @queryParam ticket_number string Exact route number. Example: RUT-2026-000001
     * @queryParam departure_from string Planned departure from. Example: 2026-11-01
     * @queryParam departure_to string Planned departure to. Example: 2026-11-30
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute paginate=15
     *
     * @apiResourceAdditional success=true message="Routes retrieved successfully."
     */
    public function index(ListRoutesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = FleetRoute::query()
            ->visibleTo($scope, (int) auth()->id())
            ->orderByDesc('planned_departure_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->whereIn('status', array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status'))))));
        }

        if ($request->filled('agency')) {
            $agency = $request->integer('agency');
            $query->where(fn ($inner) => $inner->where('origin_agency_id', $agency)->orWhere('destination_agency_id', $agency));
        }

        if ($request->filled('driver_id')) {
            $query->where('driver_id', $request->integer('driver_id'));
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->integer('vehicle_id'));
        }

        if ($request->filled('ticket_number')) {
            $query->where('ticket_number', $request->input('ticket_number'));
        }

        if ($request->filled('departure_from')) {
            $query->whereDate('planned_departure_at', '>=', $request->input('departure_from'));
        }

        if ($request->filled('departure_to')) {
            $query->whereDate('planned_departure_at', '<=', $request->input('departure_to'));
        }

        return $this->successPaginated(
            resource: RouteResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Routes retrieved successfully.',
        );
    }

    /**
     * Plan a route
     *
     * @bodyParam origin_agency_id integer required Origin agency. Example: 1
     * @bodyParam destination_agency_id integer required Destination agency. Example: 2
     * @bodyParam planned_departure_at string required Planned departure. Example: 2026-11-15T18:00:00Z
     * @bodyParam planned_arrival_at string optional Planned arrival. Example: 2026-11-16T06:00:00Z
     * @bodyParam segments object[] required Segments chaining origin to destination.
     * @bodyParam segments[].from_agency_id integer required Segment start. Example: 1
     * @bodyParam segments[].to_agency_id integer required Segment end. Example: 2
     * @bodyParam segments[].distance_km number required Distance in km. Example: 320
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function store(StoreRouteRequest $request, CreateRouteUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new RouteResource($useCase->execute($request->validated(), $scope)), 'Route planned successfully.', 201);
    }

    /**
     * Show a route
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function show(int $route, AgencyScope $scope): JsonResponse
    {
        $model = $this->visibleRoute($route, $scope)->load(['segments', 'mileageLogs']);

        return $this->success(new RouteResource($model), 'Route retrieved successfully.');
    }

    /**
     * Show the cost and consumption of a route
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @response 200 {"success":true,"message":"Route summary retrieved successfully.","data":{"route_id":1,"km":"740.00","liters":"95.00","fuel_cost":"380.00","per_diem_cost":"120.00","total_cost":"500.00","km_per_liter":7.79,"cost_per_km":0.68,"is_anomalous":false}}
     */
    public function summary(int $route, AgencyScope $scope, RouteSummaryService $summary): JsonResponse
    {
        return $this->success($summary->for($this->visibleRoute($route, $scope)), 'Route summary retrieved successfully.');
    }

    /**
     * Assign a driver and a vehicle
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @bodyParam driver_id integer required Driver. Example: 1
     * @bodyParam vehicle_id integer required Vehicle. Example: 1
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function assign(int $route, AssignRouteRequest $request, AssignRouteUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();
        $model = $useCase->execute($route, (int) $data['driver_id'], (int) $data['vehicle_id'], $scope);

        return $this->success(new RouteResource($model), 'Route assigned successfully.');
    }

    /**
     * Cancel a route
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @bodyParam reason string required Why it is cancelled. Example: Vehicle breakdown
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function cancel(int $route, CancelRouteRequest $request, CancelRouteUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($route, (string) $request->validated('reason'), $scope);

        return $this->success(new RouteResource($model), 'Route cancelled successfully.');
    }

    /**
     * Start a route
     *
     * Idempotent: starting a route already in progress has no further effects.
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @bodyParam odometer_km number required Initial reading. Example: 153200
     * @bodyParam recorded_at string optional When it was read. Example: 2026-11-15T18:00:00Z
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function start(int $route, RouteOdometerRequest $request, StartRouteUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();
        $model = $useCase->execute($route, $data['odometer_km'], $data['recorded_at'] ?? null, $scope);

        return $this->success(new RouteResource($model), 'Route started successfully.');
    }

    /**
     * Complete a segment
     *
     * @urlParam route integer required Route id. Example: 1
     * @urlParam segment integer required Segment id. Example: 1
     *
     * @response 200 {"success":true,"message":"Segment completed successfully.","data":{"id":1,"route_id":1,"sequence":1,"from_agency_id":1,"to_agency_id":2,"distance_km":"320.00","status":"completed","completed_at":"2026-11-15T23:00:00+00:00"}}
     */
    public function completeSegment(int $route, int $segment, CompleteRouteSegmentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new RouteSegmentResource($useCase->execute($route, $segment, $scope)), 'Segment completed successfully.');
    }

    /**
     * Finish a route
     *
     * Idempotent: finishing a route already finished has no further effects.
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @bodyParam odometer_km number required Final reading. Example: 153940
     * @bodyParam recorded_at string optional When it was read. Example: 2026-11-16T06:00:00Z
     *
     * @apiResource Src\Fleet\Route\Resources\RouteResource
     *
     * @apiResourceModel Src\Fleet\Route\Models\FleetRoute with=segments
     */
    public function finish(int $route, RouteOdometerRequest $request, FinishRouteUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();
        $model = $useCase->execute($route, $data['odometer_km'], $data['recorded_at'] ?? null, $scope);

        return $this->success(new RouteResource($model), 'Route finished successfully.');
    }

    /**
     * Register an odometer reading
     *
     * @urlParam route integer required Route id. Example: 1
     *
     * @bodyParam odometer_km number required Reading. Example: 153600
     * @bodyParam recorded_at string optional When it was read. Example: 2026-11-15T21:30:00Z
     * @bodyParam idempotency_key string optional Tolerates offline retries. Example: 5f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b
     * @bodyParam photo file optional Odometer photo (jpg, png or pdf, up to 5 MB).
     *
     * @response 201 {"success":true,"message":"Mileage registered successfully.","data":{"id":1,"route_id":1,"vehicle_id":1,"odometer_km":"153600.00","recorded_at":"2026-11-15T21:30:00+00:00","recorded_by":1,"has_photo":true}}
     */
    public function registerMileage(int $route, RegisterMileageRequest $request, RegisterMileageUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $log = $useCase->execute($route, $request->safe()->except('photo'), $request->file('photo'), $scope);

        return $this->success(new MileageLogResource($log), 'Mileage registered successfully.', 201);
    }

    /**
     * Download an odometer photo
     *
     * @urlParam route integer required Route id. Example: 1
     * @urlParam log integer required Mileage log id. Example: 1
     */
    public function downloadPhoto(int $route, int $log, AgencyScope $scope, FleetEvidenceStore $evidence): mixed
    {
        $this->visibleRoute($route, $scope);

        $path = MileageLog::query()->where('route_id', $route)->whereKey($log)->value('photo_path');

        if (! $evidence->exists($path)) {
            throw new BusinessRuleException(
                message: 'The photo is not available.',
                errorCode: 'FLEET_EVIDENCE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $evidence->download($path);
    }

    private function visibleRoute(int $id, AgencyScope $scope): FleetRoute
    {
        $route = FleetRoute::query()->visibleTo($scope, (int) auth()->id())->find($id);

        if ($route === null) {
            throw new BusinessRuleException(
                message: 'The route does not exist.',
                errorCode: 'ROUTE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $route;
    }
}
