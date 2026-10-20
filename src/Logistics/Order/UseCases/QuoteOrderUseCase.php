<?php

namespace Src\Logistics\Order\UseCases;

use Src\Logistics\Order\Rules\ValidateAgencyExists;
use Src\Logistics\Order\Rules\ValidateDistinctAgencies;
use Src\Logistics\Order\Rules\ValidateOrderWeight;
use Src\Logistics\Order\Services\Pricing\PricingRequest;
use Src\Logistics\Order\Services\Pricing\PricingResult;
use Src\Logistics\Order\Services\Pricing\PricingService;
use Src\Shared\Pipelines\RulesPipeline;

/**
 * Cotización sin persistir (UC-04).
 *
 * Usa el mismo motor y las mismas reglas que el alta, así que el precio que ve
 * el mostrador es exactamente el que se guardará al crear la orden.
 */
class QuoteOrderUseCase
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    public function execute(array $data): PricingResult
    {
        $data = RulesPipeline::run($data, [
            ValidateAgencyExists::class,
            ValidateDistinctAgencies::class,
            ValidateOrderWeight::class,
        ]);

        return $this->pricing->quote(PricingRequest::fromArray($data));
    }
}
