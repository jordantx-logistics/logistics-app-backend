<?php

namespace Src\Incident\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Incident\Models\Incident;
use Src\Incident\Requests\ListIncidentsRequest;
use Src\Incident\Requests\StoreIncidentRequest;
use Src\Incident\Resources\IncidentResource;
use Src\Incident\UseCases\ManageIncidentUseCase;
use Src\Incident\UseCases\OpenIncidentUseCase;
use Src\Shared\Contracts\UserDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Incidents
 *
 * Incidencias sobre órdenes, con responsable y plazo de atención.
 */
class IncidentController extends Controller
{
    /**
     * List incidents
     *
     * @queryParam status string `open`, `in_progress`, `resolved` or `closed`. Example: open
     * @queryParam type string `damage`, `loss`, `delay`, `billing` or `other`. Example: damage
     * @queryParam priority string `low`, `normal`, `high` or `urgent`. Example: high
     * @queryParam order_id integer Filter by order. Example: 1
     * @queryParam ticket_code string Filter by ticket code. Example: AB12CD34
     * @queryParam assigned_to integer Filter by responsible user. Example: 1
     * @queryParam overdue boolean Only active incidents past their deadline. Example: true
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident paginate=15
     *
     * @apiResourceAdditional success=true message="Incidents retrieved successfully."
     */
    public function index(ListIncidentsRequest $request, AgencyScope $scope): JsonResponse
    {
        $query = Incident::query()->forScope($scope)->orderByDesc('id');

        foreach (['status', 'type', 'priority', 'order_id', 'ticket_code', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        return $this->successPaginated(
            resource: IncidentResource::collection($query->paginate($request->integer('per_page', 15))),
            message: 'Incidents retrieved successfully.',
        );
    }

    /**
     * Show an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function show(int $incident, AgencyScope $scope): JsonResponse
    {
        $model = Incident::query()->forScope($scope)->find($incident);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The incident does not exist.',
                errorCode: 'INCIDENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(new IncidentResource($model), 'Incident retrieved successfully.');
    }

    /**
     * Open an incident
     *
     * The deadline comes from the incident type.
     *
     * @apiResource status=201 Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function store(StoreIncidentRequest $request, OpenIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new IncidentResource($useCase->execute($request->validated(), $scope)), 'Incident opened successfully.', 201);
    }

    /**
     * List who can attend an incident
     *
     * Users with `incidents.manage` that cover the incident's agencies.
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @response 200 scenario="Candidates" {"success": true, "message": "Assignees retrieved successfully.", "data": [{"id": 1, "name": "Ana"}]}
     */
    public function assignees(int $incident, AgencyScope $scope, UserDirectory $users): JsonResponse
    {
        $model = Incident::query()->forScope($scope)->find($incident);

        if ($model === null) {
            throw new BusinessRuleException(
                message: 'The incident does not exist.',
                errorCode: 'INCIDENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $this->success(
            $users->usersWithPermission(ManageIncidentUseCase::MANAGE_PERMISSION, [(int) $model->origin_agency_id, (int) $model->destination_agency_id]),
            'Assignees retrieved successfully.',
        );
    }

    /**
     * Assign an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @bodyParam user_id integer required Responsible user. Example: 1
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function assign(Request $request, int $incident, ManageIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);

        return $this->success(new IncidentResource($useCase->assign($incident, (int) $data['user_id'], $scope)), 'Incident assigned successfully.');
    }

    /**
     * Start attending an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function start(int $incident, ManageIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new IncidentResource($useCase->transition($incident, 'start', null, $scope)), 'Incident started successfully.');
    }

    /**
     * Resolve an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @bodyParam note string required What was done. Example: Package replaced.
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function resolve(Request $request, int $incident, ManageIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->success(new IncidentResource($useCase->transition($incident, 'resolve', $data['note'] ?? null, $scope)), 'Incident resolved successfully.');
    }

    /**
     * Close an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function close(int $incident, ManageIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new IncidentResource($useCase->transition($incident, 'close', null, $scope)), 'Incident closed successfully.');
    }

    /**
     * Reopen an incident
     *
     * @urlParam incident integer required Incident id. Example: 1
     *
     * @apiResource Src\Incident\Resources\IncidentResource
     *
     * @apiResourceModel Src\Incident\Models\Incident
     */
    public function reopen(int $incident, ManageIncidentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new IncidentResource($useCase->transition($incident, 'reopen', null, $scope)), 'Incident reopened successfully.');
    }
}
