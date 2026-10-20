<?php

namespace Src\Organization\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Organization\Models\Agency;
use Src\Organization\Requests\AgencyRequest;
use Src\Organization\Resources\AgencyResource;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Organization - Agencies
 *
 * APIs for managing branch locations.
 */
class AgencyController extends Controller
{
    /**
     * List Agencies
     *
     * Retrieves all registered agencies configured in the system.
     *
     * @apiResourceCollection Src\Organization\Resources\AgencyResource
     *
     * @apiResourceModel Src\Organization\Models\Agency
     *
     * @apiResourceAdditional success=true message="Agencies retrieved successfully."
     */
    public function index(AgencyScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Agency::class);

        // Ámbito de agencia: lo resuelve el middleware `ResolveAgencyScope`. Un
        // rol nacional (`admin`, `analyst`) llega sin filtro; el resto, con sus
        // agencias (y sin agencia no ve ninguna).
        $agencies = Agency::query()
            ->forAgencyScope($scope)
            ->orderBy('name')
            ->get();

        return $this->success(
            data: AgencyResource::collection($agencies),
            message: 'Agencies retrieved successfully.'
        );
    }

    /**
     * Create Agency
     *
     * Registers a new branch location where packages can be originated or received.
     *
     * @apiResource Src\Organization\Resources\AgencyResource status=201
     *
     * @apiResourceModel Src\Organization\Models\Agency
     *
     * @apiResourceAdditional success=true message="Agency created successfully."
     */
    public function store(AgencyRequest $request): JsonResponse
    {
        $this->authorize('create', Agency::class);

        $agency = Agency::create($request->validated());

        return $this->success(
            data: AgencyResource::make($agency),
            message: 'Agency created successfully.',
            statusCode: 201
        );
    }

    /**
     * Request Agency Details
     *
     * Retrieves detailed geographical and contact information for a specific agency.
     *
     * @apiResource Src\Organization\Resources\AgencyResource
     *
     * @apiResourceModel Src\Organization\Models\Agency
     *
     * @apiResourceAdditional success=true message="Agency details retrieved successfully."
     */
    public function show(Agency $agency): JsonResponse
    {
        $this->authorize('view', $agency);

        return $this->success(
            data: AgencyResource::make($agency),
            message: 'Agency details retrieved successfully.'
        );
    }

    /**
     * Modify Agency
     *
     * Updates an existing agency's contact information, active status, or configured properties.
     *
     * @apiResource Src\Organization\Resources\AgencyResource
     *
     * @apiResourceModel Src\Organization\Models\Agency
     *
     * @apiResourceAdditional success=true message="Agency updated successfully."
     */
    public function update(AgencyRequest $request, Agency $agency): JsonResponse
    {
        $this->authorize('update', $agency);

        $agency->update($request->validated());

        return $this->success(
            data: AgencyResource::make($agency),
            message: 'Agency updated successfully.'
        );
    }

    /**
     * Delete Agency
     *
     * Removes an agency from the system. (Warning: This action usually requires checking for relational integrity).
     *
     * @response 200 {"success": true, "message": "Agency deactivated successfully."}
     */
    public function destroy(Agency $agency): JsonResponse
    {
        $this->authorize('delete', $agency);

        // Example logic: softly deactivate instead of deleting
        $agency->update(['is_active' => false]);

        return $this->success(message: 'Agency deactivated successfully.');
    }
}
