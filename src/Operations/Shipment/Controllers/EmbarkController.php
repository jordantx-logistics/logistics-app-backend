<?php

namespace Src\Operations\Shipment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Requests\DisembarkShipmentRequest;
use Src\Operations\Shipment\Requests\EmbarkShipmentRequest;
use Src\Operations\Shipment\Requests\ListEmbarksRequest;
use Src\Operations\Shipment\Resources\DisembarkResource;
use Src\Operations\Shipment\Resources\EmbarkResource;
use Src\Operations\Shipment\UseCases\DisembarkShipmentUseCase;
use Src\Operations\Shipment\UseCases\EmbarkShipmentUseCase;
use Src\Operations\Shipment\UseCases\ListEmbarksUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Embarks
 *
 * Load manifests and disembark at destination/scale agencies.
 */
class EmbarkController extends Controller
{
    /**
     * List embarks
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam agency integer Filter by origin agency. Example: 42
     * @queryParam departed_from date Filter from this date. Example: 2026-10-01
     * @queryParam departed_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Shipment\Resources\EmbarkResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Embark paginate=15 with=items
     *
     * @apiResourceAdditional success=true message="Embarks retrieved successfully."
     */
    public function index(ListEmbarksRequest $request, ListEmbarksUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', ListEmbarksUseCase::DEFAULT_PER_PAGE), 1), ListEmbarksUseCase::MAX_PER_PAGE);

        $embarks = $useCase->execute($request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: EmbarkResource::collection($embarks),
            message: 'Embarks retrieved successfully.',
        );
    }

    /**
     * Create an embark
     *
     * @bodyParam agency_id integer required Origin agency. Example: 42
     * @bodyParam vehicle_id integer required Vehicle id. Example: 7
     * @bodyParam driver_id integer required Driver id. Example: 15
     * @bodyParam route_ref string Optional route reference. Example: LIM-TRU-01
     * @bodyParam departed_at string Optional ISO 8601 departure. Example: 2026-10-20T08:00:00Z
     * @bodyParam items object[] required Shipments in the manifest.
     * @bodyParam items[].ticket_code string required 8-char ticket code. Example: A7F3Q8X4
     * @bodyParam items[].scan_id string Optional PDA scan UUID. Example: 8a8f0a52-4f1e-4f54-9c9e-3f0b1f0a2b3c
     *
     * @apiResource Src\Operations\Shipment\Resources\EmbarkResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Embark with=items
     *
     * @apiResourceAdditional success=true message="Shipments embarked successfully." accepted=1
     */
    public function store(EmbarkShipmentRequest $request, EmbarkShipmentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($request->validated(), $scope, $request->user()?->id);

        return $this->success([
            'embark' => new EmbarkResource($result['embark']),
            'accepted' => $result['accepted'],
        ], 'Shipments embarked successfully.', 201);
    }

    /**
     * Show an embark
     *
     * @urlParam embark integer required Embark id. Example: 1
     *
     * @apiResource Src\Operations\Shipment\Resources\EmbarkResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Embark with=items
     */
    public function show(int $embark, AgencyScope $scope): JsonResponse
    {
        $model = Embark::query()
            ->withCount('items')
            ->with('items')
            ->forAgencyScope($scope)
            ->findOrFail($embark);

        return $this->success(new EmbarkResource($model), 'Embark retrieved successfully.');
    }

    /**
     * Disembark an embark
     *
     * @urlParam embark integer required Embark id. Example: 1
     *
     * @bodyParam agency_id integer required Agency where the parcels are unloaded. Example: 51
     * @bodyParam arrived_at string Optional ISO 8601 arrival. Example: 2026-10-21T15:00:00Z
     * @bodyParam items object[] Optional subset of tickets; omit to disembark the whole manifest.
     * @bodyParam items[].ticket_code string required 8-char ticket code. Example: A7F3Q8X4
     * @bodyParam items[].scan_id string Optional PDA scan UUID. Example: 8a8f0a52-4f1e-4f54-9c9e-3f0b1f0a2b3c
     *
     * @apiResource Src\Operations\Shipment\Resources\DisembarkResource
     *
     * @apiResourceModel Src\Operations\Shipment\Models\Disembark with=items
     *
     * @apiResourceAdditional success=true message="Shipments disembarked successfully." accepted=1 duplicated=0
     */
    public function disembark(int $embark, DisembarkShipmentRequest $request, DisembarkShipmentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($embark, $request->validated(), $scope, $request->user()?->id);

        return $this->success([
            'disembark' => $result['disembark'] === null ? null : new DisembarkResource($result['disembark']),
            'accepted' => $result['accepted'],
            'duplicated' => $result['duplicated'],
        ], 'Shipments disembarked successfully.');
    }
}
