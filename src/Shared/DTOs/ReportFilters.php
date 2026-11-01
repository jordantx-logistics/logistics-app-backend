<?php

namespace Src\Shared\DTOs;

use Carbon\CarbonImmutable;

/**
 * Filtros ya validados de un reporte. `agencyIds` en `null` significa alcance
 * nacional; el ámbito se resolvió al solicitar la exportación.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<int>|null  $agencyIds
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?array $agencyIds = null,
        public ?string $status = null,
    ) {}
}
