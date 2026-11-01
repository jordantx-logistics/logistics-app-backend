<?php

namespace Src\Shared\DTOs;

/**
 * A quién y dónde entregar una orden. Lo construye Core y viaja por `OrderQuery`.
 */
final readonly class DeliveryContact
{
    public function __construct(
        public ?string $recipientName,
        public ?string $recipientPhone,
        public ?string $recipientAddress,
        public bool $requiresSignature,
    ) {}
}
