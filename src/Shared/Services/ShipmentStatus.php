<?php

namespace Src\Shared\Services;

/**
 * Catálogo de estados del envío operativo.
 *
 * Vive en `Shared` porque lo usan los cinco subcontextos de Operaciones: el
 * dueño del agregado (`Operations/Shipment`) y los que solo lo leen o lo mueven
 * por puerto (`Reception`, `Inventory`, `Tracking`, `Delivery`). Las reglas de
 * transición siguen siendo del agregado; aquí solo se comparte el vocabulario.
 */
final class ShipmentStatus
{
    public const CREATED = 'created';

    public const RECEIVED = 'received';

    public const EMBARKED = 'embarked';

    public const IN_TRANSIT = 'in_transit';

    public const DISEMBARKED = 'disembarked';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /**
     * `embarked -> disembarked` se permite porque Flota (que emite
     * `route.started`) llega en la Fase 6; mientras tanto el desembarque directo
     * no puede quedar bloqueado.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::CREATED => [self::RECEIVED, self::CANCELLED],
        self::RECEIVED => [self::EMBARKED, self::CANCELLED],
        self::EMBARKED => [self::IN_TRANSIT, self::DISEMBARKED, self::CANCELLED],
        self::IN_TRANSIT => [self::DISEMBARKED],
        self::DISEMBARKED => [self::OUT_FOR_DELIVERY],
        self::OUT_FOR_DELIVERY => [self::DELIVERED, self::DELIVERY_FAILED],
        self::DELIVERY_FAILED => [self::OUT_FOR_DELIVERY, self::RETURNED],
        self::DELIVERED => [],
        self::RETURNED => [],
        self::CANCELLED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
