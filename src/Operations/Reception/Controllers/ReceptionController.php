<?php

namespace Src\Operations\Reception\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Reception\Requests\ListReceptionsRequest;
use Src\Operations\Reception\Requests\ReceiveShipmentRequest;
use Src\Operations\Reception\Resources\ReceptionResource;
use Src\Operations\Reception\UseCases\ListReceptionsUseCase;
use Src\Operations\Reception\UseCases\ReceiveShipmentUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Reception
 *
 * Physical reception of parcels at the origin agency.
 */
class ReceptionController extends Controller
{
    /**
     * List receptions
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam agency integer Filter by agency. Example: 42
     * @queryParam received_from date Filter from this date. Example: 2026-10-01
     * @queryParam received_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Reception\Resources\ReceptionResource
     *
     * @apiResourceModel Src\Operations\Reception\Models\Reception paginate=15 with=items
     *
     * @apiResourceAdditional success=true message="Receptions retrieved successfully."
     */
    public function index(ListReceptionsRequest $request, ListReceptionsUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', ListReceptionsUseCase::DEFAULT_PER_PAGE), 1), ListReceptionsUseCase::MAX_PER_PAGE);

        $receptions = $useCase->execute($request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: ReceptionResource::collection($receptions),
            message: 'Receptions retrieved successfully.',
        );
    }

    /**
     * Receive shipments
     *
     * Registers one or more parcels at their origin agency. A shipment already
     * received with the same scan is reported as duplicated, not as an error.
     *
     * @bodyParam agency_id integer required Agency where the parcels are received. Example: 42
     * @bodyParam items object[] required List of parcels.
     * @bodyParam items[].ticket_code string required 8-char ticket code. Example: A7F3Q8X4
     * @bodyParam items[].scan_id string Optional PDA scan UUID. Example: 8a8f0a52-4f1e-4f54-9c9e-3f0b1f0a2b3c
     * @bodyParam items[].scanned_at string Optional ISO 8601 timestamp. Example: 2026-10-20T10:00:00Z
     *
     * @apiResource Src\Operations\Reception\Resources\ReceptionResource
     *
     * @apiResourceModel Src\Operations\Reception\Models\Reception with=items
     *
     * @apiResourceAdditional success=true message="Shipments received successfully." accepted=1 duplicated=0
     */
    public function store(ReceiveShipmentRequest $request, ReceiveShipmentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($request->validated(), $scope, $request->user()?->id);

        return $this->success([
            'reception' => new ReceptionResource($result['reception']),
            'accepted' => $result['accepted'],
            'duplicated' => $result['duplicated'],
            'items' => $result['items'],
        ], 'Shipments received successfully.', 201);
    }
}
