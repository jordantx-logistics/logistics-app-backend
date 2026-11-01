<?php

namespace Src\Shared\DTOs;

/**
 * Un indicador del dashboard. `format` es `number` o `money`.
 */
final readonly class Kpi
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public string $format = 'number',
    ) {}
}
