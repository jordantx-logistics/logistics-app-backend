<?php

namespace Src\Logistics\Location\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Logistics\Location\Models\AgencyCoverage;
use Src\Logistics\Location\Requests\AgencyCoverageRequest;
use Src\Logistics\Location\Resources\AgencyCoverageResource;

/**
 * @group Logistics - Agency coverages
 *
 * Qué ubicaciones atiende una agencia destino, con qué modalidad y con qué
 * recargo de reparto. `agency_id` es de `Organization` y se valida por puerto.
 */
class AgencyCoverageController extends Controller
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * List Agency Coverages
     *
     * @queryParam agency_id integer Filter by agency. Example: 1
     * @queryParam location_id integer Filter by location. Example: 1
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..100, default 15). Example: 15
     *
     * @apiResourceCollection Src\Logistics\Location\Resources\AgencyCoverageResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\AgencyCoverage paginate=15
     *
     * @apiResourceAdditional success=true message="Agency coverages retrieved successfully."
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $coverages = AgencyCoverage::query()
            ->with('location.zone')
            ->when($request->filled('agency_id'), fn ($query) => $query->where('agency_id', $request->integer('agency_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->successPaginated(
            resource: AgencyCoverageResource::collection($coverages),
            message: 'Agency coverages retrieved successfully.',
        );
    }

    /**
     * Create Agency Coverage
     *
     * @apiResource Src\Logistics\Location\Resources\AgencyCoverageResource status=201
     *
     * @apiResourceModel Src\Logistics\Location\Models\AgencyCoverage
     *
     * @apiResourceAdditional success=true message="Agency coverage created successfully."
     */
    public function store(AgencyCoverageRequest $request): JsonResponse
    {
        return $this->success(
            data: AgencyCoverageResource::make(AgencyCoverage::create($request->validated())->load('location')),
            message: 'Agency coverage created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Update Agency Coverage
     *
     * @apiResource Src\Logistics\Location\Resources\AgencyCoverageResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\AgencyCoverage
     *
     * @apiResourceAdditional success=true message="Agency coverage updated successfully."
     */
    public function update(AgencyCoverageRequest $request, AgencyCoverage $coverage): JsonResponse
    {
        $coverage->update($request->validated());

        return $this->success(
            data: AgencyCoverageResource::make($coverage->refresh()->load('location')),
            message: 'Agency coverage updated successfully.',
        );
    }
}
