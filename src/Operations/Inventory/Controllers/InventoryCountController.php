<?php

namespace Src\Operations\Inventory\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Inventory\Requests\FinishInventoryCountRequest;
use Src\Operations\Inventory\Requests\ListInventoryCountsRequest;
use Src\Operations\Inventory\Requests\OpenInventoryCountRequest;
use Src\Operations\Inventory\Resources\InventoryCountListResource;
use Src\Operations\Inventory\Resources\InventoryCountResource;
use Src\Operations\Inventory\UseCases\FinishInventoryCountUseCase;
use Src\Operations\Inventory\UseCases\ListInventoryCountsUseCase;
use Src\Operations\Inventory\UseCases\OpenInventoryCountUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Inventory
 *
 * Physical inventory counts per agency.
 */
class InventoryCountController extends Controller
{
    /**
     * List inventory counts
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam agency integer Filter by agency. Example: 42
     * @queryParam status string `open` or `finished`. Example: open
     * @queryParam created_from date Filter from this date. Example: 2026-10-01
     * @queryParam created_to date Filter up to this date. Example: 2026-10-31
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Inventory\Resources\InventoryCountListResource
     *
     * @apiResourceModel Src\Operations\Inventory\Models\InventoryCount paginate=15
     *
     * @apiResourceAdditional success=true message="Inventory counts retrieved successfully."
     */
    public function index(ListInventoryCountsRequest $request, ListInventoryCountsUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', ListInventoryCountsUseCase::DEFAULT_PER_PAGE), 1), ListInventoryCountsUseCase::MAX_PER_PAGE);

        $counts = $useCase->execute($request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: InventoryCountListResource::collection($counts),
            message: 'Inventory counts retrieved successfully.',
        );
    }

    /**
     * Open an inventory count
     *
     * @bodyParam agency_id integer required Agency to count. Example: 42
     *
     * @apiResource Src\Operations\Inventory\Resources\InventoryCountResource
     *
     * @apiResourceModel Src\Operations\Inventory\Models\InventoryCount with=items
     *
     * @apiResourceAdditional success=true message="Inventory count opened successfully."
     */
    public function store(OpenInventoryCountRequest $request, OpenInventoryCountUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $count = $useCase->execute((int) $request->validated('agency_id'), $scope, $request->user()?->id);

        return $this->success(new InventoryCountResource($count), 'Inventory count opened successfully.', 201);
    }

    /**
     * Show an inventory count
     *
     * @urlParam count integer required Inventory count id. Example: 1
     *
     * @apiResource Src\Operations\Inventory\Resources\InventoryCountResource
     *
     * @apiResourceModel Src\Operations\Inventory\Models\InventoryCount with=items
     */
    public function show(int $count, ListInventoryCountsUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new InventoryCountResource($useCase->find($count, $scope)), 'Inventory count retrieved successfully.');
    }

    /**
     * Finish an inventory count
     *
     * @urlParam count integer required Inventory count id. Example: 1
     *
     * @bodyParam items object[] required Counted shipments.
     * @bodyParam items[].shipment_id integer required Shipment id. Example: 10
     * @bodyParam items[].found boolean required Whether the parcel was found. Example: true
     * @bodyParam items[].motive string Required when the expected and found states differ. Example: Damaged box
     *
     * @apiResource Src\Operations\Inventory\Resources\InventoryCountResource
     *
     * @apiResourceModel Src\Operations\Inventory\Models\InventoryCount with=items
     *
     * @apiResourceAdditional success=true message="Inventory count finished successfully." expected=1 found=1 missing=0 unexpected=0
     */
    public function finish(int $count, FinishInventoryCountRequest $request, FinishInventoryCountUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($count, $request->validated(), $scope, $request->user()?->id);

        return $this->success([
            'inventory_count' => new InventoryCountResource($result['count']),
            'expected' => $result['expected'],
            'found' => $result['found'],
            'missing' => $result['missing'],
            'unexpected' => $result['unexpected'],
        ], 'Inventory count finished successfully.');
    }
}
