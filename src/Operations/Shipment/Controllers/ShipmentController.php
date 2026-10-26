<?php

namespace Src\Operations\Shipment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Shipment\Models\Shipment;
use Src\Operations\Shipment\Requests\AgencyInventoryRequest;
use Src\Operations\Shipment\Requests\ListShipmentsRequest;
use Src\Operations\Shipment\Resources\ShipmentResource;
use Src\Operations\Shipment\UseCases\AgencyInventoryUseCase;
use Src\Operations\Shipment\UseCases\ListShipmentsUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Shipments
 *
 * Operational state of every shipment (projection of `order.created`).
 */
class ShipmentController extends Controller
{
    /**
     * List shipments
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam status string One status or a comma-separated list. Example: received,embarked
     * @queryParam agency integer Filter shipments touching this agency. Example: 42
     * @queryParam origin_agency_id integer Filter by origin agency. Example: 42
     * @queryParam destination_agency_id integer Filter by destination agency. Example: 51
     * @queryParam ticket_code string Exact 8-char ticket code. Example: A7F3Q8X4
     * @queryParam ticket_number string Ticket number prefix. Example: ORD-2026
     * @queryParam created_from date Filter from this date. Example: 2026-10-01
     * @queryParam created_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Shipment\Resources\ShipmentResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Shipment paginate=15
     *
     * @apiResourceAdditional success=true message="Shipments retrieved successfully."
     */
    public function index(ListShipmentsRequest $request, ListShipmentsUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', ListShipmentsUseCase::DEFAULT_PER_PAGE), 1), ListShipmentsUseCase::MAX_PER_PAGE);

        $shipments = $useCase->execute($request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: ShipmentResource::collection($shipments),
            message: 'Shipments retrieved successfully.',
        );
    }

    /**
     * Show a shipment
     *
     * @urlParam ticketCode string required 8-char ticket code. Example: A7F3Q8X4
     *
     * @apiResource Src\Operations\Shipment\Resources\ShipmentResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Shipment
     */
    public function show(string $ticketCode, AgencyScope $scope): JsonResponse
    {
        $shipment = Shipment::query()
            ->where('ticket_code', strtoupper($ticketCode))
            ->forAgencyScope($scope)
            ->firstOrFail();

        return $this->success(new ShipmentResource($shipment), 'Shipment retrieved successfully.');
    }

    /**
     * Agency inventory
     *
     * Shipments physically at the agency and not yet delivered, returned or cancelled.
     *
     * @urlParam agency integer required Agency id. Example: 42
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam status string One status or a comma-separated list. Example: received
     * @queryParam search string Ticket code or number prefix. Example: ORD-2026
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Shipment\Resources\ShipmentResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Shipment paginate=15
     *
     * @apiResourceAdditional success=true message="Agency inventory retrieved successfully."
     */
    public function inventory(int $agency, AgencyInventoryRequest $request, AgencyInventoryUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', AgencyInventoryUseCase::DEFAULT_PER_PAGE), 1), AgencyInventoryUseCase::MAX_PER_PAGE);

        $shipments = $useCase->execute($agency, $request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: ShipmentResource::collection($shipments),
            message: 'Agency inventory retrieved successfully.',
        );
    }
}
