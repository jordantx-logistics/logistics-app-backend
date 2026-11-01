<?php

namespace Src\Fleet\Vehicle\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Fleet\Vehicle\Requests\CorrectOdometerRequest;
use Src\Fleet\Vehicle\Requests\ListVehiclesRequest;
use Src\Fleet\Vehicle\Requests\StoreVehicleRequest;
use Src\Fleet\Vehicle\Requests\UpdateVehicleStatusRequest;
use Src\Fleet\Vehicle\Resources\VehicleResource;
use Src\Fleet\Vehicle\UseCases\CorrectOdometerUseCase;
use Src\Fleet\Vehicle\UseCases\RegisterVehicleUseCase;
use Src\Fleet\Vehicle\UseCases\UpdateVehicleStatusUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Fleet - Vehicles
 *
 * Fichas y estado de los vehículos.
 */
class VehicleController extends Controller
{
    /**
     * List vehicles
     *
     * @queryParam status string `available`, `on_route`, `maintenance` or `inactive`. Example: available
     * @queryParam agency integer Filter by owner agency. Example: 1
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Fleet\Vehicle\Resources\VehicleResource
     *
     * @apiResourceModel Src\Fleet\Vehicle\Models\Vehicle paginate=15
     *
     * @apiResourceAdditional success=true message="Vehicles retrieved successfully."
     */
    public function index(ListVehiclesRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Vehicle::query()->forAgencyScope($scope)->orderBy('plate');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('agency')) {
            $query->where('owner_agency_id', $request->integer('agency'));
        }

        return $this->successPaginated(
            resource: VehicleResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Vehicles retrieved successfully.',
        );
    }

    /**
     * Register a vehicle
     *
     * @bodyParam plate string required Plate. Example: ABC123
     * @bodyParam type string required `truck`, `van` or `trailer`. Example: truck
     * @bodyParam capacity_kg number required Load capacity in kg. Example: 12000
     * @bodyParam capacity_m3 number required Load capacity in m3. Example: 45
     * @bodyParam owner_agency_id integer required Owner agency. Example: 1
     * @bodyParam odometer_km number optional Current odometer reading. Example: 153200
     *
     * @apiResource Src\Fleet\Vehicle\Resources\VehicleResource
     *
     * @apiResourceModel Src\Fleet\Vehicle\Models\Vehicle
     */
    public function store(StoreVehicleRequest $request, RegisterVehicleUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $vehicle = $useCase->execute($request->validated(), $scope);

        return $this->success(new VehicleResource($vehicle), 'Vehicle registered successfully.', 201);
    }

    /**
     * Change the status of a vehicle
     *
     * @urlParam vehicle integer required Vehicle id. Example: 1
     *
     * @bodyParam status string required `available`, `maintenance` or `inactive`. Example: maintenance
     *
     * @apiResource Src\Fleet\Vehicle\Resources\VehicleResource
     *
     * @apiResourceModel Src\Fleet\Vehicle\Models\Vehicle
     */
    public function updateStatus(int $vehicle, UpdateVehicleStatusRequest $request, UpdateVehicleStatusUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($vehicle, (string) $request->validated('status'), $scope);

        return $this->success(new VehicleResource($model), 'Vehicle status updated successfully.');
    }

    /**
     * Correct the odometer of a vehicle
     *
     * Audited correction: the only way for a reading to go backwards.
     *
     * @urlParam vehicle integer required Vehicle id. Example: 1
     *
     * @bodyParam odometer_km number required Correct reading. Example: 153200
     * @bodyParam reason string required Why it is corrected. Example: Mistyped reading
     *
     * @apiResource Src\Fleet\Vehicle\Resources\VehicleResource
     *
     * @apiResourceModel Src\Fleet\Vehicle\Models\Vehicle
     */
    public function correctOdometer(int $vehicle, CorrectOdometerRequest $request, CorrectOdometerUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validated();
        $model = $useCase->execute($vehicle, $data['odometer_km'], $data['reason'], $scope);

        return $this->success(new VehicleResource($model), 'Odometer corrected successfully.');
    }
}
