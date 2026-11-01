<?php

namespace Src\Operations\Shipment\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Shipment\Models\Disembark;
use Src\Operations\Shipment\Models\DisembarkItem;
use Src\Operations\Shipment\Models\Embark;
use Src\Operations\Shipment\Models\EmbarkItem;
use Src\Operations\Shipment\Models\Shipment;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;

/**
 * Descarga de un manifiesto en una agencia (destino o escala).
 *
 * Sin `items` se descarga el manifiesto completo. Con `items` se permite una
 * descarga parcial; los envíos ya descargados en esa agencia se informan como
 * duplicados y no vuelven a emitir `shipment.disembarked`.
 */
class DisembarkShipmentUseCase
{
    public function __construct(
        private readonly ShipmentDirectory $shipments,
        private readonly TrackingRecorder $tracking,
    ) {}

    /**
     * @param  array{agency_id: int, arrived_at?: ?string, items?: list<array{ticket_code: string, scan_id?: ?string}>}  $data
     * @return array{disembark: ?Disembark, accepted: int, duplicated: int}
     */
    public function execute(int $embarkId, array $data, AgencyScope $scope, ?int $actorId = null): array
    {
        $agencyId = (int) $data['agency_id'];

        if (! $scope->covers($agencyId)) {
            throw new BusinessRuleException(
                message: 'The agency is outside the user scope.',
                errorCode: 'AGENCY_OUT_OF_SCOPE',
                statusCode: 403,
            );
        }

        $embark = Embark::query()->with('items')->find($embarkId);

        if ($embark === null) {
            throw new BusinessRuleException(
                message: 'The embark does not exist.',
                errorCode: 'EMBARK_NOT_FOUND',
                statusCode: 404,
            );
        }

        $targets = $this->targets($embark, $data['items'] ?? null);

        if ($targets === []) {
            throw new BusinessRuleException(
                message: 'The embark has no items to disembark.',
                errorCode: 'EMBARK_HAS_NO_ITEMS',
            );
        }

        return DB::transaction(function () use ($embark, $targets, $agencyId, $data, $actorId): array {
            $alreadyDisembarked = DisembarkItem::query()
                ->whereIn('disembark_id', Disembark::query()->where('agency_id', $agencyId)->select('id'))
                ->whereIn('shipment_id', array_column($targets, 'shipment_id'))
                ->pluck('shipment_id')
                ->all();

            if (count($alreadyDisembarked) === count($targets)) {
                $existing = Disembark::query()
                    ->where('embark_id', $embark->id)
                    ->where('agency_id', $agencyId)
                    ->latest('id')
                    ->first();

                return [
                    'disembark' => $existing?->load('items'),
                    'accepted' => 0,
                    'duplicated' => count($alreadyDisembarked),
                ];
            }

            $arrivedAt = $data['arrived_at'] ?? now()->toIso8601String();
            $disembark = Disembark::create([
                'agency_id' => $agencyId,
                'embark_id' => $embark->id,
                'arrived_at' => $arrivedAt,
            ]);

            $accepted = 0;
            $duplicated = 0;

            foreach ($targets as $target) {
                $shipmentId = (int) $target['shipment_id'];

                if (in_array($shipmentId, $alreadyDisembarked, true)) {
                    $duplicated++;

                    continue;
                }

                $scanId = $target['scan_id'] ?? null;

                if ($scanId !== null && DisembarkItem::query()->where('scan_id', $scanId)->exists()) {
                    throw new BusinessRuleException(
                        message: 'The scan has already been used in another disembark.',
                        errorCode: 'DUPLICATE_SCAN',
                    );
                }

                $shipment = $this->shipments->findById($shipmentId);

                if ($shipment === null) {
                    throw new BusinessRuleException(
                        message: 'The shipment does not exist.',
                        errorCode: 'SHIPMENT_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                if (! in_array($shipment->status, [Shipment::STATUS_EMBARKED, Shipment::STATUS_IN_TRANSIT], true)) {
                    throw new BusinessRuleException(
                        message: "The shipment cannot be disembarked from [{$shipment->status}].",
                        errorCode: 'INVALID_SHIPMENT_TRANSITION',
                    );
                }

                DisembarkItem::create([
                    'disembark_id' => $disembark->id,
                    'shipment_id' => $shipment->id,
                    'ticket_code' => $shipment->ticketCode,
                    'scan_id' => $scanId,
                ]);

                $disembarked = $this->shipments->transition(
                    shipmentId: $shipment->id,
                    to: Shipment::STATUS_DISEMBARKED,
                    currentLocationAgencyId: $agencyId,
                    from: $shipment->status,
                );

                $this->tracking->record(
                    shipment: $disembarked,
                    eventType: 'disembarked',
                    agencyId: $agencyId,
                    scanId: $scanId,
                    meta: [
                        'embark_id' => $embark->id,
                        'disembark_id' => $disembark->id,
                        'actor_id' => $actorId,
                    ],
                    occurredAt: $arrivedAt,
                );

                OutboxEvent::create([
                    'event_type' => 'shipment.disembarked',
                    'payload' => [
                        'shipment_id' => $disembarked->id,
                        'order_id' => $disembarked->orderId,
                        'ticket_code' => $disembarked->ticketCode,
                        'ticket_number' => $disembarked->ticketNumber,
                        'agency_id' => $agencyId,
                        'embark_id' => $embark->id,
                        'disembark_id' => $disembark->id,
                        'disembarked_at' => $arrivedAt,
                    ],
                ]);

                $accepted++;
            }

            return [
                'disembark' => $disembark->load('items'),
                'accepted' => $accepted,
                'duplicated' => $duplicated,
            ];
        });
    }

    /**
     * @param  list<array{ticket_code: string, scan_id?: ?string}>|null  $items
     * @return list<array{shipment_id: int, ticket_code: string, scan_id: ?string}>
     */
    private function targets(Embark $embark, ?array $items): array
    {
        $available = $embark->items->keyBy('ticket_code');

        if ($items === null) {
            return $available->map(fn ($item): array => [
                'shipment_id' => $item->shipment_id,
                'ticket_code' => $item->ticket_code,
                'scan_id' => null,
            ])->values()->all();
        }

        return array_map(function (array $item) use ($available): array {
            /** @var EmbarkItem|null $embarkItem */
            $embarkItem = $available->get($item['ticket_code']);

            if ($embarkItem === null) {
                throw new BusinessRuleException(
                    message: "The shipment [{$item['ticket_code']}] is not in this embark.",
                    errorCode: 'SHIPMENT_NOT_IN_EMBARK',
                );
            }

            return [
                'shipment_id' => $embarkItem->shipment_id,
                'ticket_code' => $embarkItem->ticket_code,
                'scan_id' => $item['scan_id'] ?? null,
            ];
        }, $items);
    }
}
