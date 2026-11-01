<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Delivery\Models\Delivery;
use Src\Operations\Delivery\Models\DeliveryEvidence;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

class CompleteDeliveryUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    /**
     * @param  array{recipient_name: string, recipient_document: string, signature_path: string, photo_path: string, latitude: float|string, longitude: float|string, captured_at: string}  $data
     * @return array{delivery: Delivery, order_id: int, shipment_id: int, already: bool}
     */
    public function execute(int $deliveryId, array $data, AgencyScope $scope): array
    {
        return DB::transaction(function () use ($deliveryId, $data, $scope): array {
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

            if ($delivery->status === Delivery::STATUS_DELIVERED) {
                $shipment = $this->shipments->findById($delivery->shipment_id);

                return [
                    'delivery' => $delivery->load('evidence'),
                    'order_id' => $shipment?->orderId ?? 0,
                    'shipment_id' => $delivery->shipment_id,
                    'already' => true,
                ];
            }

            if (! in_array($delivery->status, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_IN_PROGRESS], true)) {
                throw new BusinessRuleException(
                    message: "The delivery cannot be completed from [{$delivery->status}].",
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

            if ($shipment->status !== ShipmentStatus::OUT_FOR_DELIVERY) {
                throw new BusinessRuleException(
                    message: "The shipment cannot be delivered from [{$shipment->status}].",
                    errorCode: 'INVALID_SHIPMENT_TRANSITION',
                );
            }

            DeliveryEvidence::create([
                'delivery_id' => $delivery->id,
                'signature_path' => $data['signature_path'],
                'photo_path' => $data['photo_path'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'captured_at' => $data['captured_at'],
            ]);

            $deliveredAt = now();

            $delivery->update([
                'status' => Delivery::STATUS_DELIVERED,
                'delivered_at' => $deliveredAt,
                'recipient_name' => $data['recipient_name'],
                'recipient_document' => $data['recipient_document'],
                'failure_reason' => null,
            ]);

            $delivered = $this->shipments->transition(
                shipmentId: $shipment->id,
                to: ShipmentStatus::DELIVERED,
                currentLocationAgencyId: $delivery->agency_id,
                from: ShipmentStatus::OUT_FOR_DELIVERY,
            );

            $this->tracking->record(
                shipment: $delivered,
                eventType: 'delivered',
                agencyId: $delivery->agency_id,
                meta: [
                    'delivery_id' => $delivery->id,
                    'courier_id' => $delivery->courier_id,
                    'attempt' => $delivery->attempt,
                    'recipient_name' => $data['recipient_name'],
                    'recipient_document' => $data['recipient_document'],
                    'signature_path' => $data['signature_path'],
                    'photo_path' => $data['photo_path'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'captured_at' => $data['captured_at'],
                ],
                occurredAt: $deliveredAt->toIso8601String(),
            );

            OutboxEvent::create([
                'event_type' => 'delivery.completed',
                'payload' => [
                    'delivery_id' => $delivery->id,
                    'shipment_id' => $delivered->id,
                    'order_id' => $delivered->orderId,
                    'ticket_code' => $delivered->ticketCode,
                    'ticket_number' => $delivered->ticketNumber,
                    'agency_id' => $delivery->agency_id,
                    'courier_id' => $delivery->courier_id,
                    'attempt' => $delivery->attempt,
                    'recipient_name' => $data['recipient_name'],
                    'recipient_document' => $data['recipient_document'],
                    'signature_path' => $data['signature_path'],
                    'photo_path' => $data['photo_path'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'captured_at' => $data['captured_at'],
                    'delivered_at' => $deliveredAt->toIso8601String(),
                ],
            ]);

            return [
                'delivery' => $delivery->load('evidence'),
                'order_id' => $delivered->orderId,
                'shipment_id' => $delivered->id,
                'already' => false,
            ];
        });
    }
}
