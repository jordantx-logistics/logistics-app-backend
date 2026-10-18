<?php

namespace Src\Organization\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Organization\Models\Agency;
use Src\Organization\Requests\AgencyRequest;
use Src\Organization\Resources\AgencyResource;

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
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ámbito de agencia: un rol nacional (`admin`, `analyst`) ve todas; el
        // resto, solo las suyas (`agencyIds()`), y sin agencia no ve ninguna.
        $agencies = Agency::query()
            ->when(
                ! $user->hasNationalScope(),
                fn ($query) => $query->forAgencies($user->agencyIds()),
            )
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
        // Example logic: softly deactivate instead of deleting
        $agency->update(['is_active' => false]);

        return $this->success(message: 'Agency deactivated successfully.');
    }
}
