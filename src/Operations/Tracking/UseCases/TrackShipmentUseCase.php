<?php

namespace Src\Operations\Tracking\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Src\Operations\Tracking\Models\TrackingEvent;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\DTOs\ShipmentSnapshot;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;

class TrackShipmentUseCase
{
    public function __construct(private readonly ShipmentDirectory $shipments) {}

    /**
     * @return array{shipment: ShipmentSnapshot, events: Collection<int, TrackingEvent>}
     */
    public function execute(string $ticketCode, AgencyScope $scope): array
    {
        $shipment = $this->shipments->findByTicketCode(strtoupper($ticketCode));

        if ($shipment === null || ! $this->isVisible($shipment, $scope)) {
            throw new BusinessRuleException(
                message: 'The shipment does not exist.',
                errorCode: 'SHIPMENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        $events = TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            // Con este límite MySQL descarta las particiones anteriores. Deja un mes de
            // margen: un escaneo offline lleva la hora del dispositivo y puede ser anterior.
            ->when($shipment->createdAt, fn ($query, string $createdAt) => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($createdAt)->subMonth()->startOfMonth(),
            ))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'shipment' => $shipment,
            'events' => $events,
        ];
    }

    private function isVisible(ShipmentSnapshot $shipment, AgencyScope $scope): bool
    {
        if ($scope->unrestricted) {
            return true;
        }

        foreach ([$shipment->originAgencyId, $shipment->destinationAgencyId, $shipment->currentLocationAgencyId] as $agencyId) {
            if ($agencyId !== null && $scope->covers($agencyId)) {
                return true;
            }
        }

        return false;
    }
}
