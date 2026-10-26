<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

class FailDeliveryUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    public function execute(int $deliveryId, string $reason, AgencyScope $scope): Delivery
    {
        return DB::transaction(function () use ($deliveryId, $reason, $scope): Delivery {
            $delivery = Delivery::query()
                ->forAgencyScope($scope)
                ->lockForUpdate()
                ->find($deliveryId);

            if ($delivery === null) {
                throw new BusinessRuleException(
                    message: 'The delivery does not exist.',
                    errorCode: 'DELIVERY_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if (in_array($delivery->status, [Delivery::STATUS_FAILED, Delivery::STATUS_RETURNED], true)) {
                return $delivery;
            }

            if (! in_array($delivery->status, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_IN_PROGRESS], true)) {
                throw new BusinessRuleException(
                    message: "The delivery cannot fail from [{$delivery->status}].",
                    errorCode: 'INVALID_DELIVERY_TRANSITION',
                );
            }

            $shipment = $this->shipments->findById($delivery->shipment_id);

            if ($shipment === null) {
                throw new BusinessRuleException(
                    message: 'The shipment does not exist.',
                    errorCode: 'SHIPMENT_NOT_FOUND',
                    statusCode: 404,
                );
            }

            $delivery->update([
                'status' => Delivery::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            $failed = $this->shipments->transition(
                shipmentId: $shipment->id,
                to: ShipmentStatus::DELIVERY_FAILED,
                currentLocationAgencyId: $delivery->agency_id,
                from: ShipmentStatus::OUT_FOR_DELIVERY,
            );

            $this->tracking->record(
                shipment: $failed,
                eventType: 'delivery_failed',
                agencyId: $delivery->agency_id,
                meta: [
                    'delivery_id' => $delivery->id,
                    'courier_id' => $delivery->courier_id,
                    'attempt' => $delivery->attempt,
                    'reason' => $reason,
                ],
            );

            $maxAttempts = (int) config('operations.delivery_max_attempts', 3);
            $returnedFlag = false;

            if ($delivery->attempt >= $maxAttempts) {
                $returned = $this->shipments->transition(
                    shipmentId: $shipment->id,
                    to: ShipmentStatus::RETURNED,
                    currentLocationAgencyId: $delivery->agency_id,
                    from: ShipmentStatus::DELIVERY_FAILED,
                );

                $delivery->update(['status' => Delivery::STATUS_RETURNED]);
                $returnedFlag = true;

                $this->tracking->record(
                    shipment: $returned,
                    eventType: 'returned',
                    agencyId: $delivery->agency_id,
                    meta: [
                        'delivery_id' => $delivery->id,
                        'attempt' => $delivery->attempt,
                        'reason' => $reason,
                    ],
                );
            }

            OutboxEvent::create([
                'event_type' => 'delivery.failed',
                'payload' => [
                    'delivery_id' => $delivery->id,
                    'shipment_id' => $failed->id,
                    'order_id' => $failed->orderId,
                    'ticket_code' => $failed->ticketCode,
                    'ticket_number' => $failed->ticketNumber,
                    'agency_id' => $delivery->agency_id,
                    'courier_id' => $delivery->courier_id,
                    'attempt' => $delivery->attempt,
                    'returned' => $returnedFlag,
                    'reason' => $reason,
                ],
            ]);

            return $delivery->refresh()->load('evidence');
        });
    }
}
