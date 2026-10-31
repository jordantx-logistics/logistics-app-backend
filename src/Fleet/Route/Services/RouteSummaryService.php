<?php

namespace Src\Fleet\Route\Services;

use Src\Fleet\Expense\Models\Expense;
use Src\Fleet\Route\Models\FleetRoute;
use Src\Fleet\Route\Models\MileageLog;
use Src\Fleet\Route\ValueObjects\Odometer;
use Src\Fleet\Vehicle\Models\Vehicle;
use Src\Shared\ValueObjects\Money;

/**
 * Coste y consumo de una ruta. Los gastos rechazados no cuentan.
 */
class RouteSummaryService
{
    public function __construct(
        private readonly ConsumptionCalculator $calculator,
    ) {}

    /**
     * @return array{route_id: int, km: string, liters: string, fuel_cost: string, per_diem_cost: string, total_cost: string, km_per_liter: float|null, cost_per_km: float|null, is_anomalous: bool}
     */
    public function for(FleetRoute $route): array
    {
        $km = $this->kilometers($route);

        $expenses = Expense::query()
            ->where('route_id', $route->id)
            ->where('status', '!=', Expense::STATUS_REJECTED)
            ->get(['type', 'amount', 'liters']);

        $fuel = $this->sum($expenses->where('type', Expense::TYPE_FUEL)->pluck('amount')->all());
        $perDiem = $this->sum($expenses->where('type', Expense::TYPE_PER_DIEM)->pluck('amount')->all());
        $total = $fuel->add($perDiem);
        $liters = (float) $expenses->where('type', Expense::TYPE_FUEL)->sum(fn (Expense $expense): float => (float) $expense->liters);

        $kmPerLiter = $this->calculator->kmPerLiter($km, $liters);
        $vehicleType = $route->vehicle_id === null ? null : Vehicle::query()->whereKey($route->vehicle_id)->value('type');

        return [
            'route_id' => $route->id,
            'km' => number_format($km, 2, '.', ''),
            'liters' => number_format($liters, 2, '.', ''),
            'fuel_cost' => $fuel->toDecimal(),
            'per_diem_cost' => $perDiem->toDecimal(),
            'total_cost' => $total->toDecimal(),
            'km_per_liter' => $kmPerLiter,
            'cost_per_km' => $this->calculator->costPerKm($km, (float) $total->toDecimal()),
            'is_anomalous' => $this->calculator->isAnomalous($kmPerLiter, config("fleet.consumption.{$vehicleType}")),
        ];
    }

    /**
     * Última lectura menos la primera: es lo que realmente recorrió el vehículo
     * en esta ruta, también mientras sigue en curso.
     */
    private function kilometers(FleetRoute $route): float
    {
        if ($route->computed_km !== null) {
            return (float) $route->computed_km;
        }

        $first = MileageLog::query()->where('route_id', $route->id)->orderBy('id')->value('odometer_km');
        $last = MileageLog::query()->where('route_id', $route->id)->orderByDesc('id')->value('odometer_km');

        return $first === null ? 0.0 : Odometer::fromDecimal($last)->kmSince(Odometer::fromDecimal($first));
    }

    /**
     * @param  list<mixed>  $amounts
     */
    private function sum(array $amounts): Money
    {
        return array_reduce(
            $amounts,
            fn (Money $total, mixed $amount): Money => $total->add(Money::fromDecimal((string) $amount)),
            Money::zero(),
        );
    }
}
