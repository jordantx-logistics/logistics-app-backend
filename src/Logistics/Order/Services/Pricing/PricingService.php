<?php

namespace Src\Logistics\Order\Services\Pricing;

/**
 * Selector del motor de precios.
 *
 * El resto del contexto `Order` llama siempre a `PricingService::quote()` y no
 * sabe qué motor está activo: `PRICING_ENGINE` (`config/pricing.php`) decide
 * entre `tariff` (Fase 3) y `legacy` (rollback con el precio del MVP).
 */
final class PricingService
{
    public function __construct(
        private readonly TariffPricingEngine $tariff,
        private readonly LegacyPricingEngine $legacy,
    ) {}

    public function quote(PricingRequest $request): PricingResult
    {
        return config('pricing.engine') === 'legacy'
            ? $this->legacy->quote($request)
            : $this->tariff->quote($request);
    }

    /** ¿Está activo el motor de tarifarios? (Lo usa la cotización para el aviso). */
    public function usesTariffs(): bool
    {
        return config('pricing.engine') !== 'legacy';
    }
}
