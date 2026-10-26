<?php

namespace Src\Operations\Inventory\UseCases;

use Illuminate\Support\Facades\DB;
use Src\Operations\Inventory\Models\InventoryCount;
use Src\Operations\Inventory\Models\InventoryItem;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\ShipmentStatus;

class FinishInventoryCountUseCase
{
    private const COUNTABLE = [
        ShipmentStatus::CREATED,
        ShipmentStatus::RECEIVED,
        ShipmentStatus::EMBARKED,
        ShipmentStatus::IN_TRANSIT,
        ShipmentStatus::DISEMBARKED,
        ShipmentStatus::OUT_FOR_DELIVERY,
        ShipmentStatus::DELIVERY_FAILED,
    ];

    public function __construct(private readonly ShipmentDirectory $shipments) {}

    /**
     * @param  array{items: list<array{shipment_id: int, found: bool, motive?: ?string}>}  $data
     * @return array{count: InventoryCount, expected: int, found: int, missing: int, unexpected: int}
     */
    public function execute(int $countId, array $data, AgencyScope $scope, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($countId, $data, $scope, $actorId): array {
            $count = InventoryCount::query()
                ->forAgencyScope($scope)
                ->lockForUpdate()
                ->find($countId);

            if ($count === null) {
                throw new BusinessRuleException(
                    message: 'The inventory count does not exist.',
                    errorCode: 'INVENTORY_NOT_FOUND',
                    statusCode: 404,
                );
            }

            if ($count->status !== InventoryCount::STATUS_OPEN) {
                throw new BusinessRuleException(
                    message: 'The inventory count is already finished.',
                    errorCode: 'INVENTORY_ALREADY_FINISHED',
                );
            }

            $expected = 0;
            $found = 0;
            $missing = 0;
            $unexpected = 0;

            foreach ($data['items'] as $item) {
                $shipment = $this->shipments->findById((int) $item['shipment_id']);

                if ($shipment === null) {
                    throw new BusinessRuleException(
                        message: "The shipment [{$item['shipment_id']}] does not exist.",
                        errorCode: 'SHIPMENT_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                $isExpected = in_array($shipment->status, self::COUNTABLE, true)
                    && $shipment->currentLocationAgencyId === $count->agency_id;
                $isFound = (bool) $item['found'];

                if ($isExpected !== $isFound && empty($item['motive'])) {
                    throw new BusinessRuleException(
                        message: "A motive is required for the inventory adjustment of [{$shipment->ticketCode}].",
                        errorCode: 'INVENTORY_MOTIVE_REQUIRED',
                    );
                }

                $difference = ($isFound ? 1 : 0) - ($isExpected ? 1 : 0);

                InventoryItem::create([
                    'inventory_count_id' => $count->id,
                    'shipment_id' => $shipment->id,
                    'ticket_code' => $shipment->ticketCode,
                    'expected' => $isExpected,
                    'found' => $isFound,
                    'difference' => $difference,
                    'motive' => $item['motive'] ?? null,
                ]);

                if ($isExpected) {
                    $expected++;
                }

                if ($isFound) {
                    $found++;
                }

                if ($isExpected && ! $isFound) {
                    $missing++;
                }

                if (! $isExpected && $isFound) {
                    $unexpected++;
                }
            }

            $count->update([
                'status' => InventoryCount::STATUS_FINISHED,
                'finished_at' => now(),
                'counted_by' => $count->counted_by ?? $actorId,
            ]);

            OutboxEvent::create([
                'event_type' => 'inventory.counted',
                'payload' => [
                    'inventory_count_id' => $count->id,
                    'agency_id' => $count->agency_id,
                    'expected' => $expected,
                    'found' => $found,
                    'missing' => $missing,
                    'unexpected' => $unexpected,
                    'difference' => $found - $expected,
                    'counted_by' => $actorId,
                    'finished_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'count' => $count->load('items')->loadCount('items'),
                'expected' => $expected,
                'found' => $found,
                'missing' => $missing,
                'unexpected' => $unexpected,
            ];
        });
    }
}
