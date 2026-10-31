<?php

namespace Src\Shared\DTOs;

/**
 * Documento fiscal de un cliente (tipo y número), tal como lo necesita un
 * comprobante electrónico.
 */
final readonly class FiscalDocument
{
    public function __construct(
        public string $type,
        public string $number,
    ) {}

    public function isCompany(): bool
    {
        return strtoupper($this->type) === 'RUC';
    }

    /**
     * @return array{type: string, number: string}
     */
    public function toArray(): array
    {
        return [
            'type' => strtoupper($this->type),
            'number' => $this->number,
        ];
    }
}
