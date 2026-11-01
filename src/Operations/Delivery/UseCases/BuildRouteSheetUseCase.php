<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Support\Carbon;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Contracts\OrderQuery;
use Src\Shared\Contracts\ShipmentDirectory;

/**
 * Hoja de ruta del repartidor: sus entregas abiertas con destinatario y dirección, que
 * viven en Core y se piden por puerto. Con `updatedSince` también trae las suyas que
 * se cerraron después. Una reasignada a otro repartidor ya no aparece: la app detecta
 * esas ausencias al pedir la hoja completa, sin cursor.
 */
class BuildRouteSheetUseCase
{
    private const OPEN_STATUSES = [Delivery::STATUS_ASSIGNED, Delivery::STATUS_IN_PROGRESS];

    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly OrderQuery $orders,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(?int $employeeId, ?Carbon $updatedSince): array
    {
        if ($employeeId === null) {
            return [];
        }

        $deliveries = Delivery::query()
            ->where('courier_id', $employeeId)
            ->when(
                $updatedSince === null,
                fn ($query) => $query->whereIn('status', self::OPEN_STATUSES),
                fn ($query) => $query->where('updated_at', '>=', $updatedSince),
            )
            ->orderBy('id')
            ->limit(500)
            ->get();

        return $deliveries->map(function (Delivery $delivery): array {
            $shipment = $this->shipments->findById((int) $delivery->shipment_id);
            $contact = $shipment === null ? null : $this->orders->findDeliveryContact($shipment->orderId);

            return [
                'id' => $delivery->id,
                'ticket_code' => $delivery->ticket_code,
                'ticket_number' => $delivery->ticket_number,
                'status' => $delivery->status,
                'attempt' => $delivery->attempt,
                'recipient_name' => $contact?->recipientName,
                'recipient_phone' => $contact?->recipientPhone,
                'recipient_address' => $contact?->recipientAddress,
                'requires_signature' => $contact?->requiresSignature ?? true,
                'updated_at' => $delivery->updated_at?->toIso8601String(),
            ];
        })->all();
    }
}
