<?php

namespace Src\Operations\Tracking\UseCases;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Src\Operations\Tracking\Models\Scan;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\DTOs\ShipmentSnapshot;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

/**
 * Sincronización de escaneos de la PDA.
 *
 * Cada escaneo es idempotente por `scan_id`. Un escaneo que no aplica al estado
 * actual del envío se rechaza sin escribir nada; uno repetido se informa como
 * duplicado. Los escaneos de embarque/desembarque referencian un manifiesto
 * (`embark_id`).
 */
class SyncScansUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    /**
     * @param  array{device_id?: ?string, scans: list<array<string, mixed>>}  $data
     * @return array{accepted: int, duplicated: int, rejected: int, items: list<array<string, mixed>>}
     */
    public function execute(array $data, AgencyScope $scope): array
    {
        $accepted = 0;
        $duplicated = 0;
        $rejected = 0;
        $results = [];

        foreach ($data['scans'] as $scan) {
            $existing = Scan::query()->where('scan_id', $scan['scan_id'])->first();

            if ($existing !== null) {
                $duplicated++;
                $results[] = [
                    'scan_id' => $scan['scan_id'],
                    'status' => 'duplicated',
                    'shipment_id' => $existing->shipment_id,
                ];

                continue;
            }

            try {
                $result = DB::transaction(fn (): array => $this->process($scan, $data['device_id'] ?? null, $scope));
                $accepted++;
                $results[] = $result;
            } catch (BusinessRuleException $exception) {
                // El escaneo se conserva como evidencia aunque no mueva el
                // estado: un PDA puede sincronizar tarde y el conflicto debe
                // quedar revisable (regla `scan.conflict`).
                $this->recordRejected($scan, $data['device_id'] ?? null, $exception);

                $rejected++;
                $results[] = [
                    'scan_id' => $scan['scan_id'],
                    'status' => 'rejected',
                    'error_code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ];
            } catch (QueryException) {
                // Carrera con otro worker sobre el índice único de `scan_id`.
                $duplicated++;
                $results[] = [
                    'scan_id' => $scan['scan_id'],
                    'status' => 'duplicated',
                ];
            }
        }

        return [
            'accepted' => $accepted,
            'duplicated' => $duplicated,
            'rejected' => $rejected,
            'items' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function process(array $scan, ?string $deviceId, AgencyScope $scope): array
    {
        $type = (string) $scan['scan_type'];

        if ($type === Scan::TYPE_DELIVERY) {
            throw new BusinessRuleException(
                message: 'Delivery scans must use the delivery endpoint.',
                errorCode: 'SCAN_TYPE_NOT_SUPPORTED',
            );
        }

        if (in_array($type, [Scan::TYPE_EMBARK, Scan::TYPE_DISEMBARK], true) && empty($scan['embark_id'])) {
            throw new BusinessRuleException(
                message: 'The embark_id is required for this scan type.',
                errorCode: 'EMBARK_REQUIRED',
            );
        }

        $shipment = $this->shipments->findByTicketCode((string) $scan['ticket_code']);

        if ($shipment === null) {
            throw new BusinessRuleException(
                message: "The shipment [{$scan['ticket_code']}] does not exist.",
                errorCode: 'SHIPMENT_NOT_FOUND',
                statusCode: 404,
            );
        }

        $agencyId = $this->resolveAgency($shipment, $type, $scan);

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        $targetStatus = match ($type) {
            Scan::TYPE_RECEPTION => ShipmentStatus::RECEIVED,
            Scan::TYPE_EMBARK => ShipmentStatus::EMBARKED,
            Scan::TYPE_DISEMBARK => ShipmentStatus::DISEMBARKED,
            Scan::TYPE_OUT_FOR_DELIVERY => ShipmentStatus::OUT_FOR_DELIVERY,
            default => null,
        };

        $payload = array_filter([
            'latitude' => $scan['latitude'] ?? null,
            'longitude' => $scan['longitude'] ?? null,
            'device_id' => $deviceId,
            'agency_id' => $scan['agency_id'] ?? null,
            'embark_id' => $scan['embark_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        Scan::create([
            'scan_id' => $scan['scan_id'],
            'shipment_id' => $shipment->id,
            'embark_id' => $scan['embark_id'] ?? null,
            'scan_type' => $type,
            'device_id' => $deviceId,
            'agency_id' => $agencyId,
            'scanned_at' => $scan['scanned_at'],
            'synced_at' => now(),
            'payload' => $payload,
        ]);

        $current = $shipment;
        $transitioned = false;

        if ($targetStatus !== null && $shipment->status !== $targetStatus) {
            $current = $this->shipments->transition(
                shipmentId: $shipment->id,
                to: $targetStatus,
                currentLocationAgencyId: $agencyId,
                from: $shipment->status,
            );
            $transitioned = true;
        }

        $this->tracking->record(
            shipment: $current,
            eventType: $transitioned ? $type : 'scan.'.$type,
            agencyId: $agencyId,
            scanId: $scan['scan_id'],
            meta: [
                'source' => 'pda',
                'device_id' => $deviceId,
                'latitude' => $scan['latitude'] ?? null,
                'longitude' => $scan['longitude'] ?? null,
                'embark_id' => $scan['embark_id'] ?? null,
            ],
            occurredAt: $scan['scanned_at'],
        );

        if ($transitioned) {
            $this->publish($type, $current, $agencyId, $scan);
        }

        return [
            'scan_id' => $scan['scan_id'],
            'status' => 'accepted',
            'shipment_id' => $current->id,
            'shipment_status' => $current->status,
        ];
    }

    /**
     * Guarda el escaneo rechazado sin tocar el estado del envío.
     *
     * @param  array<string, mixed>  $scan
     */
    private function recordRejected(array $scan, ?string $deviceId, BusinessRuleException $exception): void
    {
        if (in_array($exception->errorCode, ['SCAN_TYPE_NOT_SUPPORTED', 'SHIPMENT_NOT_FOUND'], true)) {
            return;
        }

        if (Scan::query()->where('scan_id', $scan['scan_id'])->exists()) {
            return;
        }

        $shipment = $this->shipments->findByTicketCode((string) $scan['ticket_code']);

        if ($shipment === null) {
            return;
        }

        try {
            Scan::create([
                'scan_id' => $scan['scan_id'],
                'shipment_id' => $shipment->id,
                'embark_id' => $scan['embark_id'] ?? null,
                'scan_type' => $scan['scan_type'],
                'device_id' => $deviceId,
                'agency_id' => $scan['agency_id'] ?? $shipment->currentLocationAgencyId ?? $shipment->originAgencyId,
                'scanned_at' => $scan['scanned_at'],
                'synced_at' => now(),
                'payload' => array_filter([
                    'rejected' => true,
                    'error_code' => $exception->errorCode,
                    'latitude' => $scan['latitude'] ?? null,
                    'longitude' => $scan['longitude'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ]);
        } catch (QueryException) {
            // Otro worker insertó el mismo `scan_id`; la evidencia ya está.
        }
    }

    /**
     * @param  array<string, mixed>  $scan
     */
    private function resolveAgency(ShipmentSnapshot $shipment, string $type, array $scan): int
    {
        if (isset($scan['agency_id'])) {
            return (int) $scan['agency_id'];
        }

        return match ($type) {
            Scan::TYPE_RECEPTION => $shipment->originAgencyId,
            Scan::TYPE_DISEMBARK => $shipment->destinationAgencyId,
            Scan::TYPE_OUT_FOR_DELIVERY => $shipment->currentLocationAgencyId ?? $shipment->destinationAgencyId,
            default => $shipment->currentLocationAgencyId ?? $shipment->originAgencyId,
        };
    }

    /**
     * @param  array<string, mixed>  $scan
     */
    private function publish(string $type, ShipmentSnapshot $shipment, int $agencyId, array $scan): void
    {
        $event = match ($type) {
            Scan::TYPE_RECEPTION => 'shipment.received',
            Scan::TYPE_EMBARK => 'shipment.embarked',
            Scan::TYPE_DISEMBARK => 'shipment.disembarked',
            default => null,
        };

        if ($event === null) {
            return;
        }

        OutboxEvent::create([
            'event_type' => $event,
            'payload' => array_filter([
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->orderId,
                'ticket_code' => $shipment->ticketCode,
                'ticket_number' => $shipment->ticketNumber,
                'agency_id' => $agencyId,
                'embark_id' => $scan['embark_id'] ?? null,
                'scan_id' => $scan['scan_id'],
                'occurred_at' => $scan['scanned_at'],
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }
}
