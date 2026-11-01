<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Validation\Rule;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\SyncEventLedger;

/**
 * Aplica un lote de eventos de entrega de la app de reparto. La evidencia ya se subió
 * por su endpoint: aquí solo viajan sus rutas.
 */
class SyncDeliveriesUseCase
{
    public const ACTION_START = 'start';

    public const ACTION_COMPLETE = 'complete';

    public const ACTION_FAIL = 'fail';

    public const ACTIONS = [self::ACTION_START, self::ACTION_COMPLETE, self::ACTION_FAIL];

    public function __construct(
        private readonly SyncEventLedger $ledger,
        private readonly StartDeliveryUseCase $start,
        private readonly CompleteDeliveryUseCase $complete,
        private readonly FailDeliveryUseCase $fail,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{accepted: int, duplicated: int, rejected: int, items: list<array<string, mixed>>}
     */
    public function execute(array $items, AgencyScope $scope): array
    {
        return $this->ledger->process(
            $items,
            'action',
            fn (array $item): array => $this->rules((string) $item['action']),
            function (array $item) use ($scope): void {
                $deliveryId = (int) $item['delivery_id'];

                match ($item['action']) {
                    self::ACTION_START => $this->start->execute($deliveryId, $scope),
                    self::ACTION_COMPLETE => $this->complete->execute($deliveryId, $this->evidence($item), $scope),
                    self::ACTION_FAIL => $this->fail->execute($deliveryId, (string) $item['failure_reason'], $scope),
                };
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $action): array
    {
        $rules = [
            'delivery_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(self::ACTIONS)],
        ];

        return match ($action) {
            self::ACTION_COMPLETE => $rules + [
                'recipient_name' => ['required', 'string', 'max:120'],
                'recipient_document' => ['required', 'string', 'max:20'],
                'signature_path' => ['required', 'string', 'max:255', 'starts_with:deliveries/'],
                'photo_path' => ['required', 'string', 'max:255', 'starts_with:deliveries/'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'captured_at' => ['required', 'date'],
            ],
            self::ACTION_FAIL => $rules + ['failure_reason' => ['required', 'string', 'max:255']],
            default => $rules,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{recipient_name: string, recipient_document: string, signature_path: string, photo_path: string, latitude: float|string, longitude: float|string, captured_at: string}
     */
    private function evidence(array $item): array
    {
        return [
            'recipient_name' => (string) $item['recipient_name'],
            'recipient_document' => (string) $item['recipient_document'],
            'signature_path' => (string) $item['signature_path'],
            'photo_path' => (string) $item['photo_path'],
            'latitude' => $item['latitude'],
            'longitude' => $item['longitude'],
            'captured_at' => (string) $item['captured_at'],
        ];
    }
}
