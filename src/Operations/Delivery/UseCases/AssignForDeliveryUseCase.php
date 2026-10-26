<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Contracts\EmployeeDirectory;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

class AssignForDeliveryUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
        private readonly EmployeeDirectory $employees,
    ) {}

    /**
     * @param  array{agency_id: int, courier_id: int, shipment_ids: list<int>}  $data
     * @return list<Delivery>
     */
    public function execute(array $data, AgencyScope $scope): array
    {
        $agencyId = (int) $data['agency_id'];
        $courierId = (int) $data['courier_id'];

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        if (! $this->employees->employeeExists($courierId) || ! $this->employees->employeeIsActive($courierId)) {
            throw new BusinessRuleException(
                message: 'The courier does not exist or is not active.',
                errorCode: 'INVALID_COURIER',
            );
        }

        if ($this->employees->employeeAgencyId($courierId) !== $agencyId) {
            throw new BusinessRuleException(
                message: 'The courier does not belong to the agency.',
                errorCode: 'COURIER_WRONG_AGENCY',
            );
        }

        return DB::transaction(function () use ($data, $agencyId, $courierId): array {
            $deliveries = [];

            foreach ($data['shipment_ids'] as $shipmentId) {
                $shipment = $this->shipments->findById((int) $shipmentId);

                if ($shipment === null) {
                    throw new BusinessRuleException(
                        message: "The shipment [{$shipmentId}] does not exist.",
                        errorCode: 'SHIPMENT_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                if ($shipment->destinationAgencyId !== $agencyId) {
                    throw new BusinessRuleException(
                        message: 'The shipment does not belong to the delivery agency.',
                        errorCode: 'DELIVERY_WRONG_AGENCY',
                    );
                }

                if (! in_array($shipment->status, [ShipmentStatus::DISEMBARKED, ShipmentStatus::DELIVERY_FAILED], true)) {
                    throw new BusinessRuleException(
                        message: "The shipment cannot be assigned for delivery from [{$shipment->status}].",
                        errorCode: 'INVALID_SHIPMENT_TRANSITION',
                    );
                }

                $active = Delivery::query()
                    ->where('shipment_id', $shipment->id)
                    ->whereIn('status', [Delivery::STATUS_ASSIGNED, Delivery::STATUS_IN_PROGRESS])
                    ->exists();

                if ($active) {
                    throw new BusinessRuleException(
                        message: 'The shipment already has an active delivery.',
                        errorCode: 'DELIVERY_ALREADY_ACTIVE',
                    );
                }

                $attempt = (int) Delivery::query()->where('shipment_id', $shipment->id)->max('attempt') + 1;

                $delivery = Delivery::create([
                    'shipment_id' => $shipment->id,
                    'ticket_code' => $shipment->ticketCode,
                    'ticket_number' => $shipment->ticketNumber,
                    'agency_id' => $agencyId,
                    'courier_id' => $courierId,
                    'status' => Delivery::STATUS_ASSIGNED,
                    'attempt' => $attempt,
                ]);

                $outForDelivery = $this->shipments->transition(
                    shipmentId: $shipment->id,
                    to: ShipmentStatus::OUT_FOR_DELIVERY,
                    currentLocationAgencyId: $agencyId,
                    from: $shipment->status,
                );

                $this->tracking->record(
                    shipment: $outForDelivery,
                    eventType: 'out_for_delivery',
                    agencyId: $agencyId,
                    meta: [
                        'delivery_id' => $delivery->id,
                        'courier_id' => $courierId,
                        'attempt' => $attempt,
                    ],
                );

                $deliveries[] = $delivery;
            }

            return $deliveries;
        });
    }
}
