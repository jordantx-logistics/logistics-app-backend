<?php

namespace Src\Fleet\Driver\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Fleet\Driver\Models\Driver;
use Src\Fleet\Driver\Requests\ListDriversRequest;
use Src\Fleet\Driver\Requests\StoreDriverRequest;
use Src\Fleet\Driver\Requests\UpdateDriverRequest;
use Src\Fleet\Driver\Resources\DriverResource;
use Src\Fleet\Driver\UseCases\RegisterDriverUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Fleet - Drivers
 *
 * Fichas operativas de los choferes.
 */
class DriverController extends Controller
{
    /**
     * List drivers
     *
     * @queryParam status string `active`, `inactive` or `suspended`. Example: active
     * @queryParam agency integer Filter by home agency. Example: 1
     * @queryParam available boolean Only active drivers with a valid license. Example: true
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Fleet\Driver\Resources\DriverResource
     *
     * @apiResourceModel Src\Fleet\Driver\Models\Driver paginate=15
     *
     * @apiResourceAdditional success=true message="Drivers retrieved successfully."
     */
    public function index(ListDriversRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Driver::query()->forAgencyScope($scope)->orderBy('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('agency')) {
            $query->where('home_agency_id', $request->integer('agency'));
        }

        if ($request->boolean('available')) {
            $query->where('status', Driver::STATUS_ACTIVE)->whereDate('license_expires_at', '>=', now()->toDateString());
        }

        return $this->successPaginated(
            resource: DriverResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Drivers retrieved successfully.',
        );
    }

    /**
     * Register a driver
     *
     * @bodyParam user_id integer required User account of the driver. Example: 15
     * @bodyParam license_number string required License number. Example: Q12345678
     * @bodyParam license_category string required License category. Example: A-III
     * @bodyParam license_expires_at string required Expiration date (`Y-m-d`). Example: 2028-12-31
     * @bodyParam phone string optional Contact phone. Example: 999888777
     * @bodyParam home_agency_id integer required Home agency. Example: 1
     *
     * @apiResource Src\Fleet\Driver\Resources\DriverResource
     *
     * @apiResourceModel Src\Fleet\Driver\Models\Driver
     */
    public function store(StoreDriverRequest $request, RegisterDriverUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $driver = $useCase->execute($request->validated(), $scope);

        return $this->success(new DriverResource($driver), 'Driver registered successfully.', 201);
    }

    /**
     * Update a driver
     *
     * @urlParam driver integer required Driver id. Example: 1
     *
     * @bodyParam license_number string optional License number. Example: Q12345678
     * @bodyParam license_category string optional License category. Example: A-III
     * @bodyParam license_expires_at string optional Expiration date (`Y-m-d`). Example: 2028-12-31
     * @bodyParam phone string optional Contact phone. Example: 999888777
     * @bodyParam status string optional `active`, `inactive` or `suspended`. Example: suspended
     *
     * @apiResource Src\Fleet\Driver\Resources\DriverResource
     *
     * @apiResourceModel Src\Fleet\Driver\Models\Driver
     */
    public function update(int $driver, UpdateDriverRequest $request, AgencyScope $scope): JsonResponse
    {
        $model = Driver::query()->forAgencyScope($scope)->find($driver);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The driver does not exist.',
                errorCode: 'DRIVER_NOT_FOUND',
                statusCode: 404,
            );
        }

        $model->update($request->validated());

        return $this->success(new DriverResource($model), 'Driver updated successfully.');
    }
}
