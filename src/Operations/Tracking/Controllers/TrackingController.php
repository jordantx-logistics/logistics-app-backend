<?php

namespace Src\Operations\Tracking\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Src\Operations\Tracking\Requests\SyncScansRequest;
use Src\Operations\Tracking\Resources\TrackingResource;
use Src\Operations\Tracking\UseCases\SyncScansUseCase;
use Src\Operations\Tracking\UseCases\TrackShipmentUseCase;
use Src\Shared\Scope\AgencyScope;

/**
 * @group Operations - Tracking
 *
 * PDA scan synchronization and shipment timeline.
 */
class TrackingController extends Controller
{
    /**
     * Look up a shipment timeline
     *
     * @urlParam ticketCode string required 8-char ticket code. Example: A7F3Q8X4
     *
     * @response 200 scenario="Timeline" {"success":true,"message":"Tracking retrieved successfully.","data":{"shipment_id":55001,"order_id":100234,"ticket_code":"A7F3Q8X4","ticket_number":"ORD-2026-000001","status":"disembarked","origin_agency_id":10,"destination_agency_id":42,"current_location_agency_id":42,"delivered_at":null,"events":[{"id":1,"event_type":"received","status":"received","agency_id":10,"scan_id":null,"meta":{},"created_at":"2026-10-15T10:05:00+00:00"}]}}
     */
    public function show(string $ticketCode, TrackShipmentUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($ticketCode, $scope);

        return $this->success(new TrackingResource($result), 'Tracking retrieved successfully.');
    }

    /**
     * Synchronize PDA scans
     *
     * Accepts a batch of scans. Each item is processed independently and
     * returned as accepted, duplicated or rejected.
     *
     * @bodyParam device_id string Optional device identifier. Example: PDA-LIM-01
     * @bodyParam scans object[] required Batch of scans.
     * @bodyParam scans[].scan_id string required Client-generated UUID (idempotency key). Example: 8a8f0a52-4f1e-4f54-9c9e-3f0b1f0a2b3c
     * @bodyParam scans[].ticket_code string required 8-char ticket code. Example: A7F3Q8X4
     * @bodyParam scans[].scan_type string required One of reception, embark, disembark, inventory, out_for_delivery. `delivery` is reserved for the delivery endpoint. Example: reception
     * @bodyParam scans[].scanned_at string required ISO 8601 timestamp. Example: 2026-10-20T10:00:00Z
     * @bodyParam scans[].embark_id integer Required for embark/disembark scans. Example: 3
     * @bodyParam scans[].agency_id integer Optional agency override. Example: 42
     * @bodyParam scans[].latitude number Optional GPS latitude. Example: -12.0464
     * @bodyParam scans[].longitude number Optional GPS longitude. Example: -77.0428
     *
     * @response 200 scenario="Batch processed" {"success":true,"message":"Scans synchronized successfully.","data":{"accepted":1,"duplicated":0,"rejected":0,"items":[{"scan_id":"8a8f0a52-4f1e-4f54-9c9e-3f0b1f0a2b3c","status":"accepted","shipment_id":1,"shipment_status":"received"}]}}
     */
    public function sync(SyncScansRequest $request, SyncScansUseCase $useCase, AgencyScope $scope): JsonResponse
    {
        $result = $useCase->execute($request->validated(), $scope);

        return $this->success($result, 'Scans synchronized successfully.');
    }
}
