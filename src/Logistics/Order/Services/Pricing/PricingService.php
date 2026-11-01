<?php

namespace Src\Logistics\Order\Services\Pricing;

use Src\Shared\Contracts\SettingsReader;

/**
 * Selector del motor de precios.
 *
 * El resto del contexto `Order` llama siempre a `PricingService::quote()` y no
 * sabe qué motor está activo: el parámetro `pricing.engine` (Ajustes, con
 * `PRICING_ENGINE` como valor por defecto) decide entre `tariff` (Fase 3) y
 * `legacy` (rollback con el precio del MVP). Cada orden guarda su
 * `pricing_snapshot`, así que cambiarlo no altera las ya creadas.
 */
final class PricingService
{
    public function __construct(
        private readonly TariffPricingEngine $tariff,
        private readonly LegacyPricingEngine $legacy,
        private readonly SettingsReader $settings,
    ) {}

    public function quote(PricingRequest $request): PricingResult
    {
        return $this->settings->get('pricing.engine') === 'legacy'
            ? $this->legacy->quote($request)
            : $this->tariff->quote($request);
    }
}
