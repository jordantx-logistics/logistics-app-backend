<?php

namespace Src\Logistics\Location\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Logistics\Location\Models\Zone;
use Src\Logistics\Location\Requests\ZoneRequest;
use Src\Logistics\Location\Resources\ZoneResource;

/**
 * @group Logistics - Zones
 *
 * Zonas de tarificación (agrupan ubicaciones). El seed crea una por
 * departamento; el resto de la operación es edición.
 */
class ZoneController extends Controller
{
    /**
     * List Zones
     *
     * @apiResourceCollection Src\Logistics\Location\Resources\ZoneResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\Zone
     *
     * @apiResourceAdditional success=true message="Zones retrieved successfully."
     */
    public function index(): JsonResponse
    {
        $zones = Zone::query()->withCount('locations')->orderBy('code')->get();

        return $this->success(
            data: ZoneResource::collection($zones),
            message: 'Zones retrieved successfully.',
        );
    }

    /**
     * Create Zone
     *
     * @apiResource Src\Logistics\Location\Resources\ZoneResource status=201
     *
     * @apiResourceModel Src\Logistics\Location\Models\Zone
     *
     * @apiResourceAdditional success=true message="Zone created successfully."
     */
    public function store(ZoneRequest $request): JsonResponse
    {
        return $this->success(
            data: ZoneResource::make(Zone::create($request->validated())),
            message: 'Zone created successfully.',
            statusCode: 201,
        );
    }

    /**
     * Update Zone
     *
     * @apiResource Src\Logistics\Location\Resources\ZoneResource
     *
     * @apiResourceModel Src\Logistics\Location\Models\Zone
     *
     * @apiResourceAdditional success=true message="Zone updated successfully."
     */
    public function update(ZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return $this->success(
            data: ZoneResource::make($zone->refresh()),
            message: 'Zone updated successfully.',
        );
    }
}
