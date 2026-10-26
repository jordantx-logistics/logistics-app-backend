<?php

namespace Src\Operations\Delivery\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Delivery\Requests\AssignDeliveryRequest;
use Src\Operations\Delivery\Requests\CompleteDeliveryRequest;
use Src\Operations\Delivery\Requests\FailDeliveryRequest;
use Src\Operations\Delivery\Requests\ListDeliveriesRequest;
use Src\Operations\Delivery\Resources\DeliveryResource;
use Src\Operations\Delivery\UseCases\AssignForDeliveryUseCase;
use Src\Operations\Delivery\UseCases\CompleteDeliveryUseCase;
use Src\Operations\Delivery\UseCases\FailDeliveryUseCase;
use Src\Operations\Delivery\UseCases\ListDeliveriesUseCase;
use Src\Operations\Delivery\UseCases\StartDeliveryUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Delivery
 *
 * Last-mile assignment, delivery and failure with evidence by route.
 */
class DeliveryController extends Controller
{
    /**
     * List deliveries
     *
     * @queryParam page integer Page to return. Example: 2
     * @queryParam agency integer Filter by agency. Example: 42
     * @queryParam status string One status or a comma-separated list. Example: assigned,in_progress
     * @queryParam courier_id integer Filter by courier. Example: 15
     * @queryParam ticket_code string Exact 8-char ticket code. Example: A7F3Q8X4
     * @queryParam per_page integer Rows per page (1..100). Example: 15
     *
     * @apiResourceCollection Src\Operations\Delivery\Resources\DeliveryResource
     *
     * @apiResourceModel Src\Operations\Delivery\Models\Delivery paginate=15 with=evidence
     *
     * @apiResourceAdditional success=true message="Deliveries retrieved successfully."
     */
    public function index(ListDeliveriesRequest $request, ListDeliveriesUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', ListDeliveriesUseCase::DEFAULT_PER_PAGE), 1), ListDeliveriesUseCase::MAX_PER_PAGE);

        $deliveries = $useCase->execute($request->safe()->except('per_page'), $scope, $perPage);

        return $this->successPaginated(
            resource: DeliveryResource::collection($deliveries),
            message: 'Deliveries retrieved successfully.',
        );
    }

    /**
     * Show a delivery
     *
     * @urlParam delivery integer required Delivery id. Example: 1
     *
     * @apiResource Src\Operations\Delivery\Resources\DeliveryResource
     *
     * @apiResourceModel Src\Operations\Delivery\Models\Delivery with=evidence
     */
    public function show(int $delivery, ListDeliveriesUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new DeliveryResource($useCase->find($delivery, $scope)), 'Delivery retrieved successfully.');
    }

    /**
     * Assign shipments for delivery
     *
     * @bodyParam agency_id integer required Delivery agency. Example: 42
     * @bodyParam courier_id integer required Courier employee id. Example: 15
     * @bodyParam shipment_ids integer[] required Shipment ids. Example: [10,11]
     *
     * @response 201 scenario="Assigned" {"success":true,"message":"Shipments assigned for delivery successfully.","data":[{"id":1,"shipment_id":10,"ticket_code":"A7F3Q8X4","status":"assigned","attempt":1}]}
     */
    public function assign(AssignDeliveryRequest $request, AssignForDeliveryUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $deliveries = $useCase->execute($request->validated(), $scope);

        return $this->success(
            DeliveryResource::collection($deliveries),
            'Shipments assigned for delivery successfully.',
            201,
        );
    }

    /**
     * Start a delivery
     *
     * @urlParam delivery integer required Delivery id. Example: 1
     *
     * @apiResource Src\Operations\Delivery\Resources\DeliveryResource
     *
     * @apiResourceModel Src\Operations\Delivery\Models\Delivery with=evidence
     */
    public function start(int $delivery, StartDeliveryUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        return $this->success(new DeliveryResource($useCase->execute($delivery, $scope)), 'Delivery started successfully.');
    }

    /**
     * Complete a delivery
     *
     * Evidence is recorded as routes (`deliveries/...`); Fase 4 does not upload files.
     *
     * @urlParam delivery integer required Delivery id. Example: 1
     *
     * @bodyParam recipient_name string required Recipient name. Example: Ana Pérez
     * @bodyParam recipient_document string required Recipient document. Example: 12345678
     * @bodyParam signature_path string required Signature route. Example: deliveries/1/signature.png
     * @bodyParam photo_path string required Photo route. Example: deliveries/1/photo.jpg
     * @bodyParam latitude number required GPS latitude. Example: -12.0464
     * @bodyParam longitude number required GPS longitude. Example: -77.0428
     * @bodyParam captured_at string required ISO 8601 capture time. Example: 2026-10-20T15:30:00Z
     *
     * @response 200 scenario="Delivered" {"success":true,"message":"Delivery completed successfully.","data":{"delivery":{"id":1,"status":"delivered"},"order_id":10,"shipment_id":5,"already":false}}
     */
    public function complete(int $delivery, CompleteDeliveryRequest $request, CompleteDeliveryUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($delivery, $request->validated(), $scope);

        return $this->success([
            'delivery' => new DeliveryResource($result['delivery']),
            'order_id' => $result['order_id'],
            'shipment_id' => $result['shipment_id'],
            'already' => $result['already'],
        ], 'Delivery completed successfully.');
    }

    /**
     * Fail a delivery
     *
     * @urlParam delivery integer required Delivery id. Example: 1
     *
     * @bodyParam failure_reason string required Reason. Example: Recipient not found
     *
     * @apiResource Src\Operations\Delivery\Resources\DeliveryResource
     *
     * @apiResourceModel Src\Operations\Delivery\Models\Delivery with=evidence
     */
    public function fail(int $delivery, FailDeliveryRequest $request, FailDeliveryUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $model = $useCase->execute($delivery, (string) $request->validated('failure_reason'), $scope);

        return $this->success(new DeliveryResource($model), 'Delivery failed successfully.');
    }
}
