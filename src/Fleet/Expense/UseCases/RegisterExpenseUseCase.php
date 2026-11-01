<?php

namespace Src\Fleet\Expense\UseCases;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Services\FleetEvidenceStore;
use Src\Fleet\Route\Services\OdometerRecorder;
use Src\Fleet\Route\Services\RouteActorGuard;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\Contracts\SettingsReader;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Scope\AgencyScope;
use Src\Shared\ValueObjects\Money;
use Throwable;

/**
 * Registra un viático o una carga de combustible sobre una ruta abierta.
 *
 * El bloqueo de la ruta serializa los gastos de esa ruta: sin él, dos viáticos
 * simultáneos pasarían el tope cada uno por su lado. El combustible exige
 * comprobante desde el inicio; el viático lo exige al liquidarse.
 */
class RegisterExpenseUseCase
{
    public function __construct(
        private readonly RouteActorGuard $guard,
        private readonly OdometerRecorder $odometer,
        private readonly FleetEvidenceStore $evidence,
        private readonly SettingsReader $settings,
    ) {}

    /**
     * @param  array{route_id: int, type: string, amount: string|int|float, liters?: string|int|float|null, odometer_km?: string|int|float|null, idempotency_key?: ?string}  $data
     */
    public function execute(array $data, ?UploadedFile $receipt, AgencyScope $scope): Expense
    {
        $receiptPath = null;

        try {
            return DB::transaction(function () use ($data, $receipt, $scope, &$receiptPath): Expense {
                $route = FleetRoute::query()->lockForUpdate()->find((int) $data['route_id']);

                if ($route === null) {
                    throw new BusinessRuleException(
                        message: 'The route does not exist.',
                        errorCode: 'ROUTE_NOT_FOUND',
                        statusCode: 404,
                    );
                }

                $this->guard->assertCanOperate($route, $scope);

                $existing = $this->findExisting($data);

                if ($existing !== null) {
                    return $existing;
                }

                if (! in_array($route->status, [FleetRoute::STATUS_ASSIGNED, FleetRoute::STATUS_IN_PROGRESS], true)) {
                    throw new BusinessRuleException(
                        message: 'Expenses can only be registered on an assigned route or one in progress.',
                        errorCode: 'EXPENSE_ROUTE_CLOSED',
                    );
                }

                $amount = Money::fromDecimal($data['amount']);

                if ($data['type'] === Expense::TYPE_PER_DIEM) {
                    $this->assertPerDiemLimit($route, $amount);
                } else {
                    $this->assertFuelData($data, $receipt);
                }

                if (($data['odometer_km'] ?? null) !== null) {
                    $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($route->vehicle_id);
                    $this->odometer->advance($vehicle, $data['odometer_km']);
                }

                $receiptPath = $receipt === null ? null : $this->evidence->store($receipt, $route->id, $data['type']);

                $expense = Expense::create([
                    'route_id' => $route->id,
                    'driver_id' => $route->driver_id,
                    'vehicle_id' => $route->vehicle_id,
                    'type' => $data['type'],
                    'amount' => $amount->toDecimal(),
                    'currency' => $amount->currency,
                    'liters' => $data['liters'] ?? null,
                    'odometer_km' => $data['odometer_km'] ?? null,
                    'receipt_path' => $receiptPath,
                    'status' => Expense::STATUS_REGISTERED,
                    'registered_by' => (int) auth()->id(),
                    'registered_at' => now(),
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);

                OutboxEvent::create([
                    'event_type' => 'expense.registered',
                    'payload' => [
                        'expense_id' => $expense->id,
                        'route_id' => $expense->route_id,
                        'driver_id' => $expense->driver_id,
                        'vehicle_id' => $expense->vehicle_id,
                        'type' => $expense->type,
                        'amount' => $expense->amount,
                        'currency' => $expense->currency,
                        'liters' => $expense->liters,
                        'odometer_km' => $expense->odometer_km,
                        'registered_at' => $expense->registered_at?->toIso8601String(),
                    ],
                ]);

                return $expense;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // La misma clave se usó a la vez sobre otra ruta.
            $this->evidence->delete($receiptPath);

            return $this->findExisting($data) ?? throw $exception;
        } catch (Throwable $exception) {
            $this->evidence->delete($receiptPath);

            throw $exception;
        }
    }

    /**
     * Un reintento devuelve el gasto original; la misma clave con otros datos es
     * un error del cliente.
     */
    private function findExisting(array $data): ?Expense
    {
        $key = $data['idempotency_key'] ?? null;
        $existing = $key === null ? null : Expense::query()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        $same = (int) $existing->route_id === (int) $data['route_id']
            && $existing->type === $data['type']
            && Money::fromDecimal((string) $existing->amount)->equals(Money::fromDecimal($data['amount']));

        if (! $same) {
            throw new BusinessRuleException(
                message: 'The idempotency key was already used with a different expense.',
                errorCode: 'EXPENSE_IDEMPOTENCY_CONFLICT',
                statusCode: 409,
            );
        }

        return $existing;
    }

    private function assertPerDiemLimit(FleetRoute $route, Money $amount): void
    {
        $registered = Expense::query()
            ->where('route_id', $route->id)
            ->where('type', Expense::TYPE_PER_DIEM)
            ->where('status', '!=', Expense::STATUS_REJECTED)
            ->pluck('amount')
            ->reduce(fn (Money $total, mixed $value): Money => $total->add(Money::fromDecimal((string) $value)), Money::zero());

        if ($registered->add($amount)->greaterThan(Money::fromDecimal((string) $this->settings->get('fleet.per_diem_max')))) {
            throw new BusinessRuleException(
                message: 'The per diem exceeds the limit per route.',
                errorCode: 'EXPENSE_PER_DIEM_LIMIT',
            );
        }
    }

    private function assertFuelData(array $data, ?UploadedFile $receipt): void
    {
        if ($receipt === null) {
            throw new BusinessRuleException(
                message: 'A fuel load requires a receipt.',
                errorCode: 'EXPENSE_RECEIPT_REQUIRED',
            );
        }

        if ((float) ($data['liters'] ?? 0) <= 0) {
            throw new BusinessRuleException(
                message: 'A fuel load requires the liters.',
                errorCode: 'EXPENSE_LITERS_REQUIRED',
            );
        }
    }
}
