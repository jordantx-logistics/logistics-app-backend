<?php

namespace Src\Operations\Shipment\Adapters;

use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\DTOs\ShipmentSnapshot;
use Src\Shared\Exceptions\BusinessRuleException;

class EloquentShipmentDirectory implements ShipmentDirectory
{
    public function findById(int $shipmentId): ?ShipmentSnapshot
    {
        $shipment = Shipment::query()->find($shipmentId);

        return $shipment === null ? null : $this->snapshot($shipment);
    }

    public function findByTicketCode(string $ticketCode): ?ShipmentSnapshot
    {
        $shipment = Shipment::query()->where('ticket_code', $ticketCode)->first();

        return $shipment === null ? null : $this->snapshot($shipment);
    }

    public function findMany(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        return Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->get()
            ->mapWithKeys(fn (Shipment $shipment): array => [$shipment->id => $this->snapshot($shipment)])
            ->all();
    }

    public function transition(
        int $shipmentId,
        string $to,
        ?int $currentLocationAgencyId = null,
        ?string $from = null,
    ): ShipmentSnapshot {
        // El bloqueo se toma antes de validar: dos operaciones concurrentes
        // serializan aquí y la segunda ve el estado que dejó la primera.
        $shipment = Shipment::query()->lockForUpdate()->find($shipmentId);

        if ($shipment === null) {
            throw new BusinessRuleException(
                message: 'The shipment does not exist.',
                errorCode: 'SHIPMENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        if ($from !== null && $shipment->status !== $from) {
            throw new BusinessRuleException(
                message: "The shipment is no longer in [{$from}]; current status is [{$shipment->status}].",
                errorCode: 'INVALID_SHIPMENT_TRANSITION',
            );
        }

        if ($shipment->status === $to) {
            return $this->snapshot($shipment);
        }

        if (! $shipment->canTransitionTo($to)) {
            throw new BusinessRuleException(
                message: "The shipment cannot transition from [{$shipment->status}] to [{$to}].",
                errorCode: 'INVALID_SHIPMENT_TRANSITION',
            );
        }

        $attributes = ['status' => $to];

        if ($currentLocationAgencyId !== null) {
            $attributes['current_location_agency_id'] = $currentLocationAgencyId;
        }

        if ($to === Shipment::STATUS_DELIVERED) {
            $attributes['delivered_at'] = now();
        }

        $shipment->update($attributes);

        return $this->snapshot($shipment->refresh());
    }

    private function snapshot(Shipment $shipment): ShipmentSnapshot
    {
        return new ShipmentSnapshot(
            id: $shipment->id,
            orderId: $shipment->order_id,
            ticketCode: $shipment->ticket_code,
            ticketNumber: $shipment->ticket_number,
            originAgencyId: $shipment->origin_agency_id,
            destinationAgencyId: $shipment->destination_agency_id,
            currentLocationAgencyId: $shipment->current_location_agency_id,
            status: $shipment->status,
            deliveredAt: $shipment->delivered_at?->toIso8601String(),
        );
    }
}
