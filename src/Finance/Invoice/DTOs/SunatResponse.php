<?php

namespace Src\Finance\Invoice\DTOs;

/**
 * Respuesta normalizada de un gateway SUNAT/PSE.
 */
final readonly class SunatResponse
{
    public function __construct(
        public string $status,
        public string $code,
        public string $message,
        public ?string $cdr = null,
    ) {}
}
