<?php

namespace Src\Logistics\Location\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Logistics\Location\Models\Location;
use Src\Logistics\Location\Requests\LocationRequest;
use Src\Logistics\Location\Resources\LocationResource;

/**
 * @group Logistics - Locations
 *
 * Distritos del padrón INEI (`ubigeo` de 6 dígitos). Se siembran con
 * `UbigeoSeeder`; aquí se listan (paginado y filtrable) y se editan.
 */
class LocationController extends Controller
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    /**
     * List Locations
     *
     * @queryParam q string Search by ubigeo, district, province or department. Example: LIMA
     * @queryParam zone_id integer Filter by zone. Example: 1
     * @queryParam department string Filter by department. Example: LIMA
     * @queryParam is_active boolean Filter by active flag. Example: 1
     * @queryParam page integer Page to return. Example: 2
     * @queryParam per_page integer Rows per page (1..200, default 50). Example: 50
     *
     * @apiResourceCollection Src\Logistics\Location\Resources\LocationResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\Location paginate=50
     *
     * @apiResourceAdditional success=true message="Locations retrieved successfully."
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $locations = Location::query()
            ->with('zone')
            ->when($request->filled('q'), fn ($query) => $query->search((string) $request->query('q')))
            ->when($request->filled('zone_id'), fn ($query) => $query->where('zone_id', $request->integer('zone_id')))
            ->when($request->filled('department'), fn ($query) => $query->where('department', $request->query('department')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('ubigeo')
            ->paginate($perPage);

        return $this->successPaginated(
            resource: LocationResource::collection($locations),
            message: 'Locations retrieved successfully.',
        );
    }

    /**
     * Create Location
     *
     * @apiResource Src\Logistics\Location\Resources\LocationResource status=201
     *
     * @apiResourceModel Src\Logistics\Location\Models\Location
     *
     * @apiResourceAdditional success=true message="Location created successfully."
     */
    public function store(LocationRequest $request): JsonResponse
    {
        return $this->success(
            data: LocationResource::make(Location::create($request->validated())->load('zone')),
            message: 'Location created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Update Location
     *
     * Cambiar `zone_id` es cómo se reagrupa un distrito en otra zona de tarifa.
     *
     * @apiResource Src\Logistics\Location\Resources\LocationResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\Location
     *
     * @apiResourceAdditional success=true message="Location updated successfully."
     */
    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());

        return $this->success(
            data: LocationResource::make($location->refresh()->load('zone')),
            message: 'Location updated successfully.',
        );
    }
}
