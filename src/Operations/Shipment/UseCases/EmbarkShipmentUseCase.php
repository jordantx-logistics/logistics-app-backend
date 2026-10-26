<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\EmbarkItem;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Manifiesto de carga: un vehículo/ruta sale con un lote de envíos.
 *
 * Los envíos deben estar `received` en la agencia de origen del manifiesto.
 */
class EmbarkShipmentUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    /**
     * @param  array{agency_id: int, vehicle_id: int, driver_id: int, route_ref?: ?string, departed_at?: ?string, items: list<array{ticket_code: string, scan_id?: ?string, scanned_at?: ?string}>}  $data
     * @return array{embark: Embark, accepted: int}
     */
    public function execute(array $data, AgencyScope $scope, ?int $actorId = null): array
    {
        $agencyId = (int) $data['agency_id'];

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        return DB::transaction(function () use ($data, $agencyId, $actorId): array {
            $departedAt = $data['departed_at'] ?? now()->toIso8601String();

            $embark = Embark::create([
                'agency_id' => $agencyId,
                'vehicle_id' => (int) $data['vehicle_id'],
                'driver_id' => (int) $data['driver_id'],
                'route_ref' => $data['route_ref'] ?? null,
                'departed_at' => $departedAt,
            ]);

            $accepted = 0;
            $shipmentIds = [];

            foreach ($data['items'] as $item) {
                $scanId = $item['scan_id'] ?? null;

                if ($scanId !== null && EmbarkItem::query()->where('scan_id', $scanId)->exists()) {
                    throw new BusinessRuleException(
                        message: 'The scan has already been used in another embark.',
                        errorCode: 'DUPLICATE_SCAN',
                    );
                }

                $shipment = $this->shipments->findByTicketCode($item['ticket_code']);

                if ($shipment === null) {
                    throw new BusinessRuleException(
                        message: "The shipment [{$item['ticket_code']}] does not exist.",
                        errorCode: 'SHIPMENT_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                if ($shipment->originAgencyId !== $agencyId) {
                    throw new BusinessRuleException(
                        message: 'The shipment must be embarked at its origin agency.',
                        errorCode: 'EMBARK_WRONG_AGENCY',
                    );
                }

                if ($shipment->status !== Shipment::STATUS_RECEIVED) {
                    throw new BusinessRuleException(
                        message: "The shipment cannot be embarked from [{$shipment->status}].",
                        errorCode: 'INVALID_SHIPMENT_TRANSITION',
                    );
                }

                EmbarkItem::create([
                    'embark_id' => $embark->id,
                    'shipment_id' => $shipment->id,
                    'ticket_code' => $shipment->ticketCode,
                    'scan_id' => $scanId,
                ]);

                $embarked = $this->shipments->transition(
                    shipmentId: $shipment->id,
                    to: Shipment::STATUS_EMBARKED,
                    currentLocationAgencyId: $agencyId,
                    from: Shipment::STATUS_RECEIVED,
                );

                $this->tracking->record(
                    shipment: $embarked,
                    eventType: 'embarked',
                    agencyId: $agencyId,
                    scanId: $scanId,
                    meta: [
                        'embark_id' => $embark->id,
                        'vehicle_id' => (int) $data['vehicle_id'],
                        'driver_id' => (int) $data['driver_id'],
                        'route_ref' => $data['route_ref'] ?? null,
                        'actor_id' => $actorId,
                    ],
                    occurredAt: $item['scanned_at'] ?? $departedAt,
                );

                $shipmentIds[] = $embarked->id;
                $accepted++;
            }

            foreach ($shipmentIds as $shipmentId) {
                $snapshot = $this->shipments->findById($shipmentId);

                if ($snapshot === null) {
                    continue;
                }

                OutboxEvent::create([
                    'event_type' => 'shipment.embarked',
                    'payload' => [
                        'shipment_id' => $snapshot->id,
                        'shipment_ids' => $shipmentIds,
                        'order_id' => $snapshot->orderId,
                        'ticket_code' => $snapshot->ticketCode,
                        'ticket_number' => $snapshot->ticketNumber,
                        'agency_id' => $agencyId,
                        'embark_id' => $embark->id,
                        'vehicle_id' => (int) $data['vehicle_id'],
                        'driver_id' => (int) $data['driver_id'],
                        'route_ref' => $data['route_ref'] ?? null,
                        'departed_at' => $departedAt,
                    ],
                ]);
            }

            return [
                'embark' => $embark->load('items'),
                'accepted' => $accepted,
            ];
        });
    }
}
