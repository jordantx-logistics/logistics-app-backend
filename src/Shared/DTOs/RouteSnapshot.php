<?php

namespace Src\Shared\DTOs;

/**
 * Foto de una ruta troncal para los contextos que la referencian.
 */
final class RouteSnapshot
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public function __construct(
        public readonly int $id,
        public readonly string $ticketNumber,
        public readonly string $status,
        public readonly ?int $driverId,
        public readonly ?int $vehicleId,
    ) {}

    /** ¿Admite carga? Asignada, o en curso si recoge en una agencia intermedia. */
    public function acceptsCargo(): bool
    {
        return in_array($this->status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS], true);
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
