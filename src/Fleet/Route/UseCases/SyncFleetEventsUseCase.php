<?php

namespace Src\Fleet\Route\UseCases;

use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Expense\UseCases\RegisterExpenseUseCase;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\Services\SyncEventLedger;

/**
 * Aplica un lote de eventos de la app de flota. Lo que lleva archivo (combustible con
 * comprobante, kilometraje con foto) va por su endpoint multipart, con el `event_id`
 * como `idempotency_key`: mantener los archivos fuera acota el tamaño del lote.
 */
class SyncFleetEventsUseCase
{
    public const TYPE_ROUTE_START = 'route.start';

    public const TYPE_ROUTE_FINISH = 'route.finish';

    public const TYPE_SEGMENT_COMPLETE = 'segment.complete';

    public const TYPE_MILEAGE = 'mileage';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_ROUTE_START,
        self::TYPE_ROUTE_FINISH,
        self::TYPE_SEGMENT_COMPLETE,
        self::TYPE_MILEAGE,
        self::TYPE_EXPENSE,
    ];

    public function __construct(
        private readonly SyncEventLedger $ledger,
        private readonly StartRouteUseCase $start,
        private readonly FinishRouteUseCase $finish,
        private readonly CompleteRouteSegmentUseCase $segment,
        private readonly RegisterMileageUseCase $mileage,
        private readonly RegisterExpenseUseCase $expense,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{accepted: int, duplicated: int, rejected: int, items: list<array<string, mixed>>}
     */
    public function execute(array $items, AgencyScope $scope): array
    {
        return $this->ledger->process(
            $items,
            'type',
            fn (array $item): array => $this->rules((string) $item['type']),
            fn (array $item) => $this->apply($item, $scope),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function apply(array $item, AgencyScope $scope): void
    {
        $this->assertPermission((string) $item['type']);

        $routeId = (int) $item['route_id'];

        match ($item['type']) {
            self::TYPE_ROUTE_START => $this->start->execute($routeId, $item['odometer_km'], $item['recorded_at'], $scope),
            self::TYPE_ROUTE_FINISH => $this->finish->execute($routeId, $item['odometer_km'], $item['recorded_at'], $scope),
            self::TYPE_SEGMENT_COMPLETE => $this->segment->execute($routeId, (int) $item['segment_id'], $scope),
            self::TYPE_MILEAGE => $this->mileage->execute($routeId, [
                'odometer_km' => $item['odometer_km'],
                'recorded_at' => $item['recorded_at'],
                'idempotency_key' => $item['event_id'],
            ], null, $scope),
            self::TYPE_EXPENSE => $this->expense->execute([
                'route_id' => $routeId,
                'type' => $item['expense_type'],
                'amount' => $item['amount'],
                'liters' => $item['liters'] ?? null,
                'odometer_km' => $item['odometer_km'] ?? null,
                'idempotency_key' => $item['event_id'],
            ], null, $scope),
        };
    }

    /**
     * La ruta del lote admite cualquiera de los dos permisos: cada evento comprueba el suyo.
     */
    private function assertPermission(string $type): void
    {
        $permission = $type === self::TYPE_EXPENSE ? 'expenses.register' : 'routes.execute';

        if (! auth()->user()?->can($permission)) {
            throw new BusinessRuleException(
                message: 'You are not allowed to register this event.',
                errorCode: 'FLEET_SYNC_FORBIDDEN',
                statusCode: 403,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $type): array
    {
        $route = ['route_id' => ['required', 'integer', 'min:1']];
        $reading = [
            'odometer_km' => ['required', 'numeric', 'min:0'],
            'recorded_at' => ['required', 'date'],
        ];

        return match ($type) {
            self::TYPE_ROUTE_START, self::TYPE_ROUTE_FINISH, self::TYPE_MILEAGE => $route + $reading,
            self::TYPE_SEGMENT_COMPLETE => $route + ['segment_id' => ['required', 'integer', 'min:1']],
            default => $route + [
                'expense_type' => ['required', 'string', 'in:'.implode(',', Expense::TYPES)],
                'amount' => ['required', 'numeric', 'gt:0'],
                'liters' => ['nullable', 'numeric', 'gt:0'],
                'odometer_km' => ['nullable', 'numeric', 'min:0'],
            ],
        };
    }
}
