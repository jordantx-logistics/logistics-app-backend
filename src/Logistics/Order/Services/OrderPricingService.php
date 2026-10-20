<?php

namespace Src\Logistics\Order\Services;

class OrderPricingService
{
    /**
     * Calculates the price of an order based on weight and volume.
     */
    public function calculate(array $data): float
    {
        $weight = $data['weight_kg'] ?? 0;
        $volume = $data['volume_m3'] ?? 0;

        // Base rate: 5.00
        // 2.50 per kg
        // 50.00 per cubic meter

        $price = 5.00 + ($weight * 2.50) + ($volume * 50.00);

        return round($price, 2);
    }
}
