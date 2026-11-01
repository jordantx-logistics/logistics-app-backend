<?php

namespace Src\Operations\Reception\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Reception\Models\Reception;
use Src\Operations\Reception\Models\ReceptionItem;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

/**
 * Alta física de paquetes en una agencia.
 *
 * Acepta un lote de `ticket_code` (con `scan_id` opcional) y es idempotente:
 * un envío ya recibido no vuelve a emitir `shipment.received`; un `scan_id`
 * repetido se informa como duplicado.
 */
class ReceiveShipmentUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    /**
     * @param  array{agency_id: int, items: list<array{ticket_code: string, scan_id?: ?string, scanned_at?: ?string}>}  $data
     * @return array{reception: Reception, accepted: int, duplicated: int, items: list<array<string, mixed>>}
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
            $reception = Reception::create([
                'agency_id' => $agencyId,
                'received_by' => $actorId,
                'received_at' => now(),
                'status' => 'completed',
            ]);

            $accepted = 0;
            $duplicated = 0;
            $results = [];

            foreach ($data['items'] as $item) {
                $scanId = $item['scan_id'] ?? null;

                if ($scanId !== null && ReceptionItem::query()->where('scan_id', $scanId)->exists()) {
                    $duplicated++;
                    $results[] = ['ticket_code' => $item['ticket_code'], 'status' => 'duplicated'];

                    continue;
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
                        message: 'The shipment must be received at its origin agency.',
                        errorCode: 'RECEPTION_WRONG_AGENCY',
                    );
                }

                if ($shipment->status === ShipmentStatus::CANCELLED) {
                    throw new BusinessRuleException(
                        message: 'The shipment is cancelled.',
                        errorCode: 'SHIPMENT_CANCELLED',
                    );
                }

                if ($shipment->status !== ShipmentStatus::CREATED) {
                    throw new BusinessRuleException(
                        message: "The shipment cannot be received from [{$shipment->status}].",
                        errorCode: $shipment->status === ShipmentStatus::RECEIVED
                            ? 'RECEPTION_ALREADY_DONE'
                            : 'INVALID_SHIPMENT_TRANSITION',
                    );
                }

                ReceptionItem::create([
                    'reception_id' => $reception->id,
                    'shipment_id' => $shipment->id,
                    'ticket_code' => $shipment->ticketCode,
                    'scan_id' => $scanId,
                ]);

                $received = $this->shipments->transition(
                    shipmentId: $shipment->id,
                    to: ShipmentStatus::RECEIVED,
                    currentLocationAgencyId: $agencyId,
                    from: ShipmentStatus::CREATED,
                );

                $this->tracking->record(
                    shipment: $received,
                    eventType: 'received',
                    agencyId: $agencyId,
                    scanId: $scanId,
                    meta: ['reception_id' => $reception->id],
                    occurredAt: $item['scanned_at'] ?? null,
                );

                OutboxEvent::create([
                    'event_type' => 'shipment.received',
                    'payload' => [
                        'shipment_id' => $received->id,
                        'order_id' => $received->orderId,
                        'ticket_code' => $received->ticketCode,
                        'ticket_number' => $received->ticketNumber,
                        'agency_id' => $agencyId,
                        'received_at' => now()->toIso8601String(),
                    ],
                ]);

                $accepted++;
                $results[] = [
                    'ticket_code' => $shipment->ticketCode,
                    'shipment_id' => $shipment->id,
                    'status' => 'accepted',
                ];
            }

            return [
                'reception' => $reception->load('items'),
                'accepted' => $accepted,
                'duplicated' => $duplicated,
                'items' => $results,
            ];
        });
    }
}
